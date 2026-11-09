<?php

namespace App\Comms\Gate;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Events\Source as EventSource;
use App\Comms\People\Relation;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The follow-up question's draft. The user types it; a model does not.
 */
class QuestionDraft
{
    public function __construct(
        private EventLog $log,
        private Gate $gate,
    ) {}

    public function save(User $user, CommsQuestion $question, string $text): void
    {
        $this->log->append(
            user: $user,
            type: EventType::UserAnswered,
            source: EventSource::User,
            externalId: 'draft:'.$question->key,
            payload: null,
            normalized: [
                'kind' => 'draft',
                'question_key' => $question->key,
                'draft' => $text,
            ],
            causation: null,
            occurredAt: CarbonImmutable::now(),
            revision: (string) Str::uuid(),
        );
    }

    public function propose(User $user, CommsQuestion $question): ?CommsProposal
    {
        $text = trim((string) ($question->context['draft'] ?? ''));
        $intent = $question->intent;

        if ($text === '' || $intent === null) {
            return null;
        }

        $message = CommsMessage::query()
            ->whereIn('id', $intent->links()->pluck('message_id'))
            ->orderByDesc('occurred_at')
            ->first();
        $slack = $message === null || $message->source === Source::Slack;
        $responder = $intent->participants()->with('person.identities')->get()
            ->first(fn ($participant): bool => $participant->person->relation !== Relation::Self);
        $identity = $responder?->person->identities->first();
        $ref = $identity !== null ? $identity->source->value.':'.$identity->external_id : 'self';

        return $this->gate->submit(
            user: $user,
            class: $slack ? ActionClass::SendSlack : ActionClass::SendEmailReply,
            target: [
                'ref' => $ref,
                'channel' => $message?->channel_ref ?? '',
                'thread' => $message?->thread_ref,
            ],
            payload: $slack
                ? [
                    'text' => $text,
                    'to' => (string) ($message?->channel_ref ?? $identity?->external_id ?? ''),
                    'to_label' => $responder?->person->display_name ?? 'Slack',
                    'thread_ref' => $message?->thread_ref,
                ]
                : [
                    'text' => $text,
                    'to_email' => (string) ($identity?->email ?? ''),
                    'to_name' => $responder?->person->display_name ?? '',
                    'from' => 'me@example.com',
                    'subject' => 'Re: '.$intent->what,
                    'thread_id' => $message?->thread_ref,
                    'in_reply_to' => $message?->external_id,
                    'references' => $message?->external_id,
                ],
            intent: $intent,
            userInitiated: true,
        );
    }
}
