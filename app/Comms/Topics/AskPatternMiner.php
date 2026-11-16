<?php

namespace App\Comms\Topics;

use App\Comms\Context\ContextWriter;
use App\Comms\Context\FactOrigin;
use App\Comms\Linking\Schemas;
use App\Comms\Models\ModelAnswerKind;
use App\Comms\Models\ModelRouter;
use App\Models\Comms\CommsGoldenLabel;
use App\Models\Comms\CommsMessage;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Backfill pass B3, also run monthly. One ask per person who has enough
 * labelled messages. At most five observed rules.
 */
class AskPatternMiner
{
    public function __construct(private ModelRouter $router, private ContextWriter $writer) {}

    /**
     * @return array{people: int, facts: int}
     */
    public function run(User $user): array
    {
        $minimum = max(1, (int) config('comms.backfill.ask_pattern_min', 20));
        $groups = $this->groups($user);
        $people = 0;
        $facts = 0;

        foreach ($groups as $personId => $buckets) {
            $acted = $buckets['acted'];
            $ignored = $buckets['ignored'];

            if (count($acted) + count($ignored) < $minimum) {
                continue;
            }

            $people++;
            $facts += $this->ask($user, $personId, $acted, $ignored);
        }

        return ['people' => $people, 'facts' => $facts];
    }

    /**
     * @return array<int, array{acted: list<CommsMessage>, ignored: list<CommsMessage>}>
     */
    private function groups(User $user): array
    {
        $labels = CommsGoldenLabel::query()
            ->whereBelongsTo($user)
            ->where('kind', 'weak_label')
            ->whereIn('label', ['acted', 'ignored'])
            ->orderBy('id')
            ->get();
        $ids = $labels->pluck('message_id')->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit((string) $id)))->map(intval(...))->all();

        if ($ids === []) {
            return [];
        }

        $messages = CommsMessage::query()
            ->whereBelongsTo($user)
            ->whereIn('id', $ids)
            ->where('direction', 'in')
            ->whereNull('deleted_at')
            ->with('author')
            ->get()
            ->keyBy('id');
        $groups = [];
        $seen = [];

        foreach ($labels as $label) {
            $message = $messages->get($label->message_id);

            if (! $message instanceof CommsMessage || $message->author_person_id === null || isset($seen[$message->id])) {
                continue;
            }

            $seen[$message->id] = true;
            $personId = $message->author_person_id;

            if (! isset($groups[$personId])) {
                $groups[$personId] = ['acted' => [], 'ignored' => []];
            }

            $groups[$personId][$label->label][] = $message;
        }

        foreach ($groups as $personId => $buckets) {
            $groups[$personId]['acted'] = $this->ordered($buckets['acted']);
            $groups[$personId]['ignored'] = $this->ordered($buckets['ignored']);
        }

        return $groups;
    }

    /**
     * @param  list<CommsMessage>  $messages
     * @return list<CommsMessage>
     */
    private function ordered(array $messages): array
    {
        usort($messages, fn (CommsMessage $left, CommsMessage $right): int => [$left->occurred_at->getTimestamp(), $left->id]
            <=> [$right->occurred_at->getTimestamp(), $right->id]);

        return $messages;
    }

    /**
     * @param  list<CommsMessage>  $acted
     * @param  list<CommsMessage>  $ignored
     */
    private function ask(User $user, int $personId, array $acted, array $ignored): int
    {
        $messages = [...$acted, ...$ignored];
        $answer = $this->router->ask(
            $user,
            'ask_pattern',
            $this->input($personId, $acted, $ignored),
            Schemas::askPattern(),
            array_values(array_unique(array_map(fn (CommsMessage $message): int => $message->event_id, $messages))),
        );

        if ($answer->kind !== ModelAnswerKind::Accepted || ! is_array($answer->output)) {
            return 0;
        }

        $allowed = [];

        foreach ($messages as $message) {
            $allowed[$message->id] = true;
        }

        $operations = [];

        foreach ($this->patterns($answer->output['patterns'] ?? null) as $pattern) {
            if (count($operations) === 5) {
                break;
            }

            $refs = [];

            foreach ($pattern['examples'] as $example) {
                if (isset($allowed[$example])) {
                    $refs[] = 'm'.$example;
                }
            }

            $operations[] = [
                'op' => 'add',
                'kind' => 'rule',
                'subject' => 'person:'.$personId,
                'attribute' => 'rule',
                'value' => ['text' => Str::limit($pattern['text'], 240, '')],
                'audiences' => [],
                'confidence' => 0.7,
                'refs' => $refs,
            ];
        }

        if ($operations === []) {
            return 0;
        }

        $this->writer->record(
            $user,
            FactOrigin::Observed,
            $operations,
            revision: 'ask-pattern:'.$personId.':'.hash('sha1', (string) json_encode($operations, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        );

        return count($operations);
    }

    /**
     * @param  list<CommsMessage>  $acted
     * @param  list<CommsMessage>  $ignored
     */
    private function input(int $personId, array $acted, array $ignored): string
    {
        $sample = $acted[0] ?? $ignored[0];
        $name = $sample->author?->display_name ?? '';

        return implode("\n\n", [
            "PERSON\nperson:{$personId} {$name}",
            "ACTED\n".$this->sources($acted),
            "IGNORED\n".$this->sources($ignored),
        ]);
    }

    /**
     * @param  list<CommsMessage>  $messages
     */
    private function sources(array $messages): string
    {
        if ($messages === []) {
            return '(none)';
        }

        return implode("\n", array_map(function (CommsMessage $message): string {
            $channel = $message->source->value.($message->channel_ref === null || $message->channel_ref === '' ? '' : ':'.$message->channel_ref);

            return '<source id="m'.$message->id.'" author="person:'.$message->author_person_id.'" at="'.$message->occurred_at->toIso8601String().'" channel="'.$channel.'">'
                .$message->text.'</source>';
        }, $messages));
    }

    /**
     * @return list<array{text: string, examples: list<int>}>
     */
    private function patterns(mixed $patterns): array
    {
        if (! is_array($patterns)) {
            return [];
        }

        $parsed = [];

        foreach ($patterns as $pattern) {
            if (! is_array($pattern) || ! is_string($pattern['text'] ?? null) || trim($pattern['text']) === '') {
                continue;
            }

            $examples = [];

            foreach (is_array($pattern['example_message_ids'] ?? null) ? $pattern['example_message_ids'] : [] as $id) {
                if (! is_string($id) && ! is_int($id)) {
                    continue;
                }

                $raw = is_int($id) ? (string) $id : trim($id);

                if (str_starts_with($raw, 'm')) {
                    $raw = substr($raw, 1);
                }

                if (ctype_digit($raw)) {
                    $examples[] = (int) $raw;
                }
            }

            $parsed[] = ['text' => trim($pattern['text']), 'examples' => $examples];
        }

        return $parsed;
    }
}
