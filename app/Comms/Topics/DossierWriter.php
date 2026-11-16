<?php

namespace App\Comms\Topics;

use App\Comms\Context\ContextEngine;
use App\Comms\Context\ContextWriter;
use App\Comms\Context\FactOrigin;
use App\Comms\Linking\Schemas;
use App\Comms\Models\ModelAnswerKind;
use App\Comms\Models\ModelRouter;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsSegment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Keeps a topic's written state current after a window was applied to it.
 * One call per topic. Sensitive sentences never stay in the summary.
 */
class DossierWriter
{
    public function __construct(
        private ModelRouter $router,
        private ContextWriter $writer,
        private ContextEngine $context,
    ) {}

    /**
     * @param  list<CommsSegment>  $segments
     * @param  list<array<string, mixed>>  $applied
     * @param  list<int>  $intentIds
     */
    public function write(User $user, string $topic, array $segments, array $applied, array $intentIds): void
    {
        if ($topic === '' || $segments === []) {
            return;
        }

        $messages = $this->messages($user, $segments);
        $secrets = $this->secrets($messages);
        $answer = $this->router->ask(
            $user,
            'dossier_write',
            $this->input($user, $topic, $segments, $applied, $messages),
            Schemas::dossier(),
            $this->eventIds($messages),
            $secrets !== [],
            windowId: $segments[0]->window_id,
        );

        if ($answer->kind !== ModelAnswerKind::Accepted || ! is_array($answer->output)) {
            return;
        }

        $facts = $this->facts($user);
        $summary = $this->visible($this->text($answer->output['summary'] ?? null), $secrets);
        $state = $this->visible($this->text($answer->output['state'] ?? null), $secrets);
        $changed = Str::limit($this->visible($this->text($answer->output['changed'] ?? null), $secrets), 280, '');
        $terms = $this->terms($answer->output['new_terms'] ?? null, $secrets);

        if ($summary === '') {
            $summary = $this->current($facts, $topic, 'summary');
        }

        $this->store($user, $topic, $summary, $state, $terms, $messages);

        if ($changed === '' || $intentIds === []) {
            return;
        }

        CommsIntent::query()
            ->whereBelongsTo($user)
            ->whereIn('id', $intentIds)
            ->update([
                'changed_summary' => $changed,
                'changed_at' => CarbonImmutable::now(),
            ]);
    }

    /**
     * @param  list<CommsSegment>  $segments
     * @param  list<array<string, mixed>>  $applied
     * @param  list<CommsMessage>  $messages
     */
    private function input(User $user, string $topic, array $segments, array $applied, array $messages): string
    {
        return implode("\n\n", [
            "PREVIOUS DOSSIER\n".$this->previous($this->facts($user), $topic),
            "APPLIED\n".$this->applied($applied),
            "SEGMENT\n".$this->segment($segments, $messages),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     */
    private function previous(array $facts, string $topic): string
    {
        $names = [];

        foreach ($facts as $fact) {
            $value = is_array($fact['value'] ?? null) ? $fact['value'] : [];

            if (($fact['kind'] ?? null) === 'term' && ($value['value'] ?? null) === $topic && is_string($value['text'] ?? null)) {
                $names[] = $value['text'];
            }
        }

        return 'summary: '.$this->current($facts, $topic, 'summary')
            ."\nstate: ".$this->current($facts, $topic, 'state')
            ."\nnicknames: ".implode(', ', $names);
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     */
    private function current(array $facts, string $topic, string $attribute): string
    {
        foreach ($facts as $fact) {
            if (($fact['subject'] ?? null) !== $topic || ($fact['attribute'] ?? null) !== $attribute) {
                continue;
            }

            $value = is_array($fact['value'] ?? null) ? $fact['value'] : [];

            return is_string($value['text'] ?? null) ? $value['text'] : '';
        }

        return '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function facts(User $user): array
    {
        return $this->context->listing($user, ['active'], ['workstream', 'term'], null, 500);
    }

    /**
     * @param  list<array<string, mixed>>  $applied
     */
    private function applied(array $applied): string
    {
        if ($applied === []) {
            return '(none)';
        }

        $lines = [];

        foreach ($applied as $operation) {
            $op = is_string($operation['op'] ?? null) && $operation['op'] !== '' ? $operation['op'] : 'update';
            $what = is_string($operation['what'] ?? null) ? $operation['what'] : '';
            $deadline = is_string($operation['deadline'] ?? null) ? $operation['deadline'] : '';
            $line = '- '.$op;

            if ($what !== '') {
                $line .= ': '.$what;
            }

            if ($deadline !== '') {
                $line .= ' (deadline '.$deadline.')';
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<CommsSegment>  $segments
     * @param  list<CommsMessage>  $messages
     */
    private function segment(array $segments, array $messages): string
    {
        $blocks = [];

        foreach ($segments as $segment) {
            $ids = array_flip(array_map(intval(...), $segment->message_ids ?? []));
            $sources = [];

            foreach ($messages as $message) {
                if (! isset($ids[$message->id])) {
                    continue;
                }

                $author = $message->author_person_id === null ? '' : 'person:'.$message->author_person_id;
                $sources[] = '<source id="m'.$message->id.'" author="'.$author.'" at="'.$message->occurred_at->toIso8601String().'">'
                    .$message->text.'</source>';
            }

            $blocks[] = 'restated: '.$segment->restated."\n".implode("\n", $sources);
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @param  list<CommsSegment>  $segments
     * @return list<CommsMessage>
     */
    private function messages(User $user, array $segments): array
    {
        $ids = [];

        foreach ($segments as $segment) {
            foreach ($segment->message_ids ?? [] as $id) {
                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $ids[] = (int) $id;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        return CommsMessage::query()
            ->whereBelongsTo($user)
            ->whereIn('id', array_values(array_unique($ids)))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @param  list<CommsMessage>  $messages
     * @return list<int>
     */
    private function eventIds(array $messages): array
    {
        return array_values(array_unique(array_map(
            fn (CommsMessage $message): int => $message->event_id,
            $messages,
        )));
    }

    /**
     * @param  list<CommsMessage>  $messages
     * @return list<string>
     */
    private function secrets(array $messages): array
    {
        $secrets = [];

        foreach ($messages as $message) {
            if (($message->flags['sensitive'] ?? false) !== true) {
                continue;
            }

            $plain = $this->plain($message->text);

            if (mb_strlen($plain) >= 8) {
                $secrets[] = $plain;
            }
        }

        return $secrets;
    }

    /**
     * @param  list<string>  $secrets
     */
    private function visible(string $text, array $secrets): string
    {
        $text = trim($text);

        if ($text === '' || $secrets === []) {
            return $text;
        }

        $parts = preg_split('/(?<=[.!?])\s*/u', $text) ?: [];
        $kept = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '' || $this->leaks($part, $secrets)) {
                continue;
            }

            $kept[] = $part;
        }

        return trim(implode(' ', $kept));
    }

    /**
     * @param  list<string>  $secrets
     */
    private function leaks(string $text, array $secrets): bool
    {
        $plain = $this->plain($text);

        foreach ($secrets as $secret) {
            if ($plain !== '' && (str_contains($plain, $secret) || (mb_strlen($plain) >= 12 && str_contains($secret, $plain)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function terms(mixed $terms, array $secrets): array
    {
        if (! is_array($terms)) {
            return [];
        }

        $kept = [];

        foreach ($terms as $term) {
            if (! is_string($term) || trim($term) === '' || $this->leaks($term, $secrets)) {
                continue;
            }

            $kept[] = trim($term);
        }

        return $kept;
    }

    /**
     * @param  list<string>  $terms
     * @param  list<CommsMessage>  $messages
     */
    private function store(User $user, string $topic, string $summary, string $state, array $terms, array $messages): void
    {
        $refs = array_map(fn (CommsMessage $message): string => 'm'.$message->id, $messages);
        $fact = function (string $kind, string $subject, string $attribute, array $value) use ($refs): array {
            return [
                'op' => 'set',
                'kind' => $kind,
                'subject' => $subject,
                'attribute' => $attribute,
                'value' => $value,
                'audiences' => [],
                'confidence' => 0.8,
                'refs' => $refs,
            ];
        };
        $operations = [];

        if ($summary !== '') {
            $operations[] = $fact('workstream', $topic, 'summary', ['text' => $summary]);
        }

        if ($state !== '') {
            $operations[] = $fact('workstream', $topic, 'state', ['text' => $state]);
        }

        $seen = [];

        foreach ($terms as $term) {
            $slug = Str::limit(Str::slug($term), 120, '') ?: 'x';

            if (isset($seen[$slug])) {
                continue;
            }

            $seen[$slug] = true;
            $operations[] = $fact('term', 'term:'.$slug, 'refers_to', [
                'text' => $term,
                'value' => $topic,
            ]);
        }

        if ($operations === []) {
            return;
        }

        $this->writer->record(
            $user,
            FactOrigin::Inferred,
            $operations,
            revision: 'dossier:'.hash('sha1', (string) json_encode($operations, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        );
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function plain(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text, " \t\n\r\0\x0B.");
    }
}
