<?php

namespace App\Comms\Playbooks;

use App\Comms\Context\CorpusDocument;
use App\Comms\Models\ModelAnswerKind;
use App\Comms\Notify\Notifier;
use App\Comms\Playbooks\Steps\Ask;
use App\Comms\Playbooks\Steps\Gather;
use App\Comms\Playbooks\Steps\Propose;
use App\Comms\Playbooks\Steps\Resolve;
use App\Comms\Playbooks\Steps\Watch;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsOwnedArea;
use App\Models\Comms\CommsProposal;
use App\Models\User;
use Illuminate\Contracts\Container\Container;

/**
 * Cases 1, 9, 19, 23, 37 and 60. A sentence with no source does not
 * reach a proposal. A compliance ask is a draft and nothing else.
 */
class AnswerFromSources
{
    public function __construct(
        private Gather $gather,
        private Ask $ask,
        private Propose $propose,
        private Watch $watch,
        private Notifier $notifier,
        private Container $container,
    ) {}

    public function run(User $user, CommsIntent $intent, CommsMessage $message): void
    {
        $gathered = $this->gather->forIntent($user, $intent);
        $shortcut = $this->shortcut($gathered);

        if ($shortcut !== null) {
            $output = ['points' => [['text' => $shortcut['text'], 'refs' => [$shortcut['ref']], 'unknown' => false]]];
        } else {
            $answer = $this->resolver()->answer($user, $intent, $message);

            if ($answer->kind === ModelAnswerKind::NoDraft || ! is_array($answer->output)) {
                $this->noDraft($user, $intent, $message);

                return;
            }

            $output = $answer->output;
        }

        $this->apply($user, $intent, $message, $gathered, $output, proposing: true);
        $this->watch->arm();
    }

    /**
     * @param  array<string, mixed>  $output
     */
    public function applyStored(User $user, CommsIntent $intent, CommsMessage $message, array $output): void
    {
        $this->apply($user, $intent, $message, [
            'hits' => [],
            'findings' => [],
        ], $output, proposing: false);
    }

    /**
     * @param  array{hits?: list<array{document: CorpusDocument, similarity: float}>, findings?: list<array{refs: list<string>, summary: string}>}  $gathered
     * @param  array<string, mixed>  $output
     */
    private function apply(User $user, CommsIntent $intent, CommsMessage $message, array $gathered, array $output, bool $proposing): void
    {
        $rendered = $this->render($gathered, $output);
        $kind = $intent->flags['kind'] ?? null;
        $restricted = $this->restricted($user);
        $choice = $kind === 'choice';
        $draft = $rendered['draft'];

        if ($proposing && ! $restricted && ! $choice && $draft !== '') {
            $checked = $this->resolver()->verify($user, $message, $draft);

            if ($checked->kind === ModelAnswerKind::NoDraft) {
                $this->noDraft($user, $intent, $message);

                return;
            }

            if (is_array($checked->output)) {
                $draft = $this->kept($checked->output['keep'] ?? [], $rendered['refs']);
            }
        }

        if ($rendered['unknowns'] !== [] || $restricted || $choice || $draft === '') {
            $text = $choice
                ? $rendered['options']
                : ($rendered['unknowns'] !== []
                    ? 'No está resuelto: '.implode(' ', $rendered['unknowns'])
                    : 'Borrador para revisar, no se envía.');

            if ($text !== '') {
                $this->ask->open($user, $intent, $text, ['draft' => $draft], [$message->event_id]);
            }
        }

        if (! $proposing || $restricted || $choice || $draft === '') {
            return;
        }

        $latest = $this->latest($intent) ?? $message;
        $existing = $this->proposal($intent);

        if ($existing !== null && ($existing->target['channel'] ?? null) === (string) $latest->channel_ref) {
            return;
        }

        $this->propose->submit($user, $intent, $latest, $draft, $rendered['refs']);
    }

    /**
     * @param  array{hits?: list<array{document: CorpusDocument, similarity: float}>, findings?: list<array{refs: list<string>, summary: string}>}  $gathered
     * @param  array<string, mixed>  $output
     * @return array{draft: string, unknowns: list<string>, refs: list<string>, options: string}
     */
    private function render(array $gathered, array $output): array
    {
        $refs = [];

        foreach ($gathered['hits'] ?? [] as $hit) {
            $refs[] = $hit['document']->ref;
        }

        $lines = [];
        $unknowns = [];
        $options = [];

        foreach ($output['points'] ?? [] as $point) {
            if (! is_array($point)) {
                continue;
            }

            if (($point['unknown'] ?? false) === true) {
                $found = trim((string) ($point['found'] ?? $point['text'] ?? ''));

                if ($found !== '') {
                    $unknowns[] = $found;
                }

                continue;
            }

            $pointRefs = array_values(array_filter($point['refs'] ?? [], is_string(...)));
            $text = trim((string) ($point['text'] ?? ''));

            if ($text === '' || $pointRefs === []) {
                continue;
            }

            $refs = [...$refs, ...$pointRefs];
            $options[] = $text;

            foreach (preg_split('/(?<=\.)\s+/u', $text) ?: [] as $sentence) {
                $sentence = trim((string) $sentence);

                if ($sentence !== '' && $this->cites($sentence, $pointRefs)) {
                    $lines[] = $sentence;
                }
            }
        }

        foreach ($gathered['findings'] ?? [] as $finding) {
            $lines[] = $finding['summary'];
            $refs = [...$refs, ...$finding['refs']];
            $options[] = $finding['summary'];
        }

        return [
            'draft' => implode(' ', $lines),
            'unknowns' => $unknowns,
            'refs' => array_values(array_unique($refs)),
            'options' => implode(' ', $options),
        ];
    }

    /**
     * @param  list<mixed>  $keep
     * @param  list<string>  $refs
     */
    private function kept(array $keep, array $refs): string
    {
        $sentences = [];

        foreach ($keep as $sentence) {
            if (is_string($sentence) && $this->cites($sentence, $refs)) {
                $sentences[] = trim($sentence);
            }
        }

        return implode(' ', $sentences);
    }

    /**
     * @param  list<string>  $refs
     */
    private function cites(string $sentence, array $refs): bool
    {
        foreach ($refs as $ref) {
            if ($ref !== '' && str_contains($sentence, $ref)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{hits?: list<array{document: CorpusDocument, similarity: float}>, findings?: list<array{refs: list<string>, summary: string}>}  $gathered
     * @return array{text: string, ref: string}|null
     */
    private function shortcut(array $gathered): ?array
    {
        if (($gathered['findings'] ?? []) !== []) {
            return null;
        }

        foreach ($gathered['hits'] ?? [] as $hit) {
            if ($hit['similarity'] < 85) {
                continue;
            }

            $body = trim($hit['document']->body);
            $sentence = trim((string) (preg_split('/(?<=\.)\s+/u', $body)[0] ?? $body));

            return ['text' => $hit['document']->ref.' '.$sentence, 'ref' => $hit['document']->ref];
        }

        return null;
    }

    private function restricted(User $user): bool
    {
        return CommsOwnedArea::query()
            ->whereBelongsTo($user)
            ->whereIn('kind', ['compliance', 'legal', 'security'])
            ->exists();
    }

    private function proposal(CommsIntent $intent): ?CommsProposal
    {
        return CommsProposal::query()->where('intent_id', $intent->id)->first();
    }

    private function latest(CommsIntent $intent): ?CommsMessage
    {
        return CommsMessage::query()
            ->whereIn('id', $intent->links()->pluck('message_id'))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }

    private function resolver(): Resolve
    {
        return $this->container->make(Resolve::class);
    }

    private function noDraft(User $user, CommsIntent $intent, CommsMessage $message): void
    {
        $this->notifier->store($user, 'no_draft', 'digest', 'intent', $intent->id, 'n:nodraft:'.$message->event_id, [$message->event_id]);
    }
}
