<?php

namespace App\Comms\Playbooks\Steps;

use App\Comms\Events\Source;
use App\Comms\Gate\ActionClass;
use App\Comms\Gate\Gate;
use App\Comms\Intents\PersonRef;
use App\Connections\Capability;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsProposal;
use App\Models\User;

/**
 * One reply, on the channel of the latest message, citing what was kept.
 */
class Propose
{
    public function __construct(private Gate $gate, private PersonRef $people) {}

    /**
     * @param  list<string>  $refs
     */
    public function submit(User $user, CommsIntent $intent, CommsMessage $latest, string $text, array $refs, bool $facts = true): CommsProposal
    {
        $intent->loadMissing('participants.person.identities');
        $requester = $intent->participants->first(fn ($participant): bool => $participant->role === 'requester');
        $person = $requester?->person;
        $email = $person?->identities->first(fn ($identity): bool => filled($identity->email))?->email
            ?? $person?->identities->first()?->email;
        $class = $this->classFor($latest);

        return $this->gate->submit(
            user: $user,
            class: $class,
            target: [
                'ref' => $person !== null ? $this->people->key($person) : 'self',
                'channel' => (string) $latest->channel_ref,
                'thread' => $latest->thread_ref,
            ],
            payload: $class === ActionClass::SendEmailReply
                ? $this->emailPayload($user, $intent, $latest, $text, $person?->display_name, $email)
                : [
                    'text' => $text,
                    'to' => (string) $latest->channel_ref,
                    'to_label' => $person?->display_name ?? 'hilo',
                    'to_email' => $email ?? '',
                    'to_name' => $person?->display_name ?? '',
                    'thread_id' => $latest->thread_ref,
                    'thread_ref' => $latest->thread_ref,
                ],
            intent: $intent,
            sourceEventIds: [$latest->event_id],
            statesProductFacts: $facts,
            verification: $facts ? ['passed' => true, 'refs' => $refs] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function emailPayload(
        User $user,
        CommsIntent $intent,
        CommsMessage $latest,
        string $text,
        ?string $toName,
        ?string $toEmail,
    ): array {
        $account = $user->accountsWith(Capability::GmailSend)->first();
        $messageId = (string) $latest->external_id;
        $original = $account !== null && $messageId !== '' && ! str_contains($messageId, '.')
            ? $account->id.'.'.$messageId
            : ($messageId !== '' ? $messageId : null);

        return [
            'text' => $text,
            'to' => (string) ($toEmail ?? ''),
            'to_label' => $toName ?? 'email',
            'to_email' => (string) ($toEmail ?? ''),
            'to_name' => $toName ?? '',
            'account_id' => $account?->id,
            'from' => (string) ($account?->email ?? ''),
            'subject' => 'Re: '.$intent->what,
            'thread_id' => $latest->thread_ref,
            'in_reply_to' => $messageId !== '' ? $messageId : null,
            'references' => $messageId !== '' ? $messageId : null,
            'original' => $original,
        ];
    }

    private function classFor(CommsMessage $message): ActionClass
    {
        return match ($message->source) {
            Source::Jira => ActionClass::JiraComment,
            Source::Notion => ActionClass::NotionComment,
            Source::Gmail => ActionClass::SendEmailReply,
            default => ActionClass::SendSlack,
        };
    }
}
