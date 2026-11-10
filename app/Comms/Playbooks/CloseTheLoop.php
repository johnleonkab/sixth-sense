<?php

namespace App\Comms\Playbooks;

use App\Comms\Context\ContextEngine;
use App\Comms\Context\CorpusDocument;
use App\Comms\Gate\ActionClass;
use App\Comms\Gate\Gate;
use App\Comms\Playbooks\Steps\Propose;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsWatcher;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Cases 15, 22, 41 and 44. A finished source tells whoever was waiting.
 * A decision is written down. A shipped feature is watched for a week.
 * The event text is never copied into a proposal.
 */
class CloseTheLoop
{
    public function __construct(private Propose $propose, private Gate $gate, private ContextEngine $context) {}

    public function source(CommsEvent $event): void
    {
        $user = $event->user;
        $data = $event->normalized ?? [];
        $kind = (string) ($data['kind'] ?? '');

        if ($user === null) {
            return;
        }

        if (in_array($kind, ['status', 'merged', 'published'], true)) {
            $this->delivered($user, $event, (string) ($data['ref'] ?? ''), (string) ($data['text'] ?? ''));
        }

        if ($kind === 'shipped') {
            $this->shipped($user, $event, (string) ($data['ref'] ?? ''));
        }
    }

    public function decision(User $user, CommsIntent $intent, CommsMessage $message, string $quote): void
    {
        $quote = trim($quote);

        if ($quote === '') {
            return;
        }

        $expects = (string) $intent->expects;

        if (str_contains($expects, 'jira:')) {
            $this->record($user, $intent, $message, ActionClass::JiraUpdate, $quote, ['issue' => $expects]);
        }

        $document = $this->outdated($user, $intent, $quote);

        if ($document === null) {
            return;
        }

        $diff = '- '.trim($document->body)."\n+ ".$quote;
        $this->record($user, $intent, $message, ActionClass::NotionEdit, $diff, ['ref' => $document->ref, 'diff' => $diff]);
    }

    private function delivered(User $user, CommsEvent $event, string $ref, string $text): void
    {
        $intent = $this->waiting($user, $ref, $text);

        if ($intent === null) {
            return;
        }

        $intent->state = 'settled';
        $intent->settle_reason = 'taken_by_other';
        $intent->settled_at = $event->occurred_at;
        $intent->save();

        $message = $this->latest($intent);
        $text = $ref.' está hecho.';

        if ($message === null || $ref === '' || $this->alreadySaid($user, $text)) {
            return;
        }

        $this->propose->submit($user, $intent, $message, $text, [], facts: false);
    }

    private function shipped(User $user, CommsEvent $event, string $ref): void
    {
        $intent = $this->waiting($user, $ref, $ref, openOnly: false);

        if ($intent === null || $ref === '') {
            return;
        }

        $message = $this->latest($intent);
        $text = 'Ya está: '.$ref.'.';

        if ($message !== null && ! $this->alreadySaid($user, $text)) {
            $this->propose->submit($user, $intent, $message, $text, [], facts: false);
        }

        CommsWatcher::query()->firstOrCreate(
            ['user_id' => $user->id, 'key' => $intent->key.':support'],
            [
                'intent_id' => $intent->id,
                'condition_type' => 'support',
                'condition' => ['ref' => $ref],
                'deadline_at' => CarbonImmutable::parse($event->occurred_at)->addDays(7),
                'attempt' => 0,
                'on_fire' => 'close_the_loop',
                'state' => 'active',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(User $user, CommsIntent $intent, CommsMessage $message, ActionClass $class, string $text, array $extra): void
    {
        if ($this->alreadySaid($user, $text)) {
            return;
        }

        $proposal = $this->gate->submit(
            user: $user,
            class: $class,
            target: [
                'ref' => 'self',
                'channel' => (string) $message->channel_ref,
                'thread' => $message->thread_ref,
            ],
            payload: ['text' => $text, ...$extra],
            intent: $intent,
            sourceEventIds: [$message->event_id],
        );
        $proposal->save();
    }

    private function alreadySaid(User $user, string $text): bool
    {
        return CommsProposal::query()->whereBelongsTo($user)->where('payload->text', $text)->exists();
    }

    private function waiting(User $user, string $ref, string $text, bool $openOnly = true): ?CommsIntent
    {
        $haystack = mb_strtolower(trim($ref.' '.$text));

        if ($haystack === '') {
            return null;
        }

        return CommsIntent::query()
            ->whereBelongsTo($user)
            ->when($openOnly, fn ($query) => $query->where('state', 'open'))
            ->get()
            ->first(function (CommsIntent $intent) use ($haystack): bool {
                $expects = mb_strtolower(trim((string) $intent->expects));

                return $expects !== '' && str_contains($haystack, $expects);
            });
    }

    private function latest(CommsIntent $intent): ?CommsMessage
    {
        return CommsMessage::query()
            ->whereIn('id', $intent->links()->pluck('message_id'))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }

    private function outdated(User $user, CommsIntent $intent, string $quote): ?CorpusDocument
    {
        $words = array_values(array_filter(
            preg_split('/\s+/u', mb_strtolower($intent->what.' '.$quote)) ?: [],
            fn (string $word): bool => mb_strlen($word) >= 4,
        ));

        foreach ($this->context->corpus($user, ['notion']) as $document) {
            if (str_contains($document->body, $quote)) {
                continue;
            }

            $haystack = mb_strtolower($document->title.' '.$document->body);

            foreach ($words as $word) {
                if (str_contains($haystack, $word)) {
                    return $document;
                }
            }
        }

        return null;
    }
}
