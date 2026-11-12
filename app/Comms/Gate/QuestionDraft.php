<?php

namespace App\Comms\Gate;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Events\Source as EventSource;
use App\Comms\People\Relation;
use App\Connections\Capability;
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
                : $this->emailPayload($user, $text, $intent->what, $message, $responder?->person->display_name, $identity?->email),
            intent: $intent,
            userInitiated: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function emailPayload(
        User $user,
        string $text,
        string $what,
        ?CommsMessage $message,
        ?string $toName,
        ?string $toEmail,
    ): array {
        $account = $user->accountsWith(Capability::GmailSend)->first();
        $messageId = (string) ($message?->external_id ?? '');
        $original = $account !== null && $messageId !== '' && ! str_contains($messageId, '.')
            ? $account->id.'.'.$messageId
            : ($messageId !== '' ? $messageId : null);

        return [
            'text' => $text,
            'to_email' => (string) ($toEmail ?? ''),
            'to_name' => $toName ?? '',
            'account_id' => $account?->id,
            'from' => (string) ($account?->email ?? ''),
            'subject' => 'Re: '.$what,
            'thread_id' => $message?->thread_ref,
            'in_reply_to' => $messageId !== '' ? $messageId : null,
            'references' => $messageId !== '' ? $messageId : null,
            'original' => $original,
        ];
    }
}
