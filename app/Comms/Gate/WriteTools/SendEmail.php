<?php

namespace App\Comms\Gate\WriteTools;

use App\Comms\Gate\ActionClass;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\MailSender;
use App\Connectors\Mail\Outbox;
use App\Connectors\Mail\OutgoingEmail;
use App\Models\Comms\CommsProposal;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sends a reply or a new email. Headers for a reply come from the thread.
 */
class SendEmail
{
    public function __construct(private Outbox $outbox, private Mailbox $mailbox) {}

    public function send(User $user, CommsProposal $proposal): string
    {
        if (filled($proposal->external_id)) {
            return $proposal->external_id;
        }

        $payload = $proposal->payload;
        $reply = $proposal->action_class === ActionClass::SendEmailReply;
        $sender = $this->sender($user, $payload);
        $toEmail = (string) ($payload['to_email'] ?? '');

        if ($toEmail === '' || ! str_contains($toEmail, '@')) {
            throw new RuntimeException('No hay un destinatario de email en la propuesta.');
        }

        $originalRef = $this->originalRef($user, $payload, $sender);
        $original = null;

        if ($reply && $originalRef !== null) {
            try {
                $original = $this->mailbox->message($user, $originalRef);
            } catch (\Throwable) {
                $original = null;
            }
        }
        $inReplyTo = $original?->messageId ?? $this->header($payload['in_reply_to'] ?? null);
        $references = $original?->references ?? $original?->messageId ?? $this->header($payload['references'] ?? null);
        $threadId = $original?->threadId ?? $this->threadId($payload['thread_id'] ?? null);

        return $this->outbox->send($user, new OutgoingEmail(
            mode: $reply ? 'reply' : 'new',
            accountId: $sender->accountId,
            from: $sender->email,
            to: [new MailAddress((string) ($payload['to_name'] ?? $toEmail), $toEmail)],
            cc: [],
            subject: (string) ($payload['subject'] ?? ''),
            body: (string) ($payload['text'] ?? ''),
            original: $originalRef,
            threadId: $threadId,
            inReplyTo: $inReplyTo,
            references: $references,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sender(User $user, array $payload): MailSender
    {
        $accountId = (int) ($payload['account_id'] ?? 0);

        if ($accountId > 0) {
            $match = collect($this->outbox->senders($user))->first(
                fn (MailSender $sender): bool => $sender->accountId === $accountId,
            );

            if ($match !== null) {
                return $match;
            }
        }

        $original = (string) ($payload['original'] ?? '');

        if ($original !== '') {
            $match = $this->outbox->senderFor($user, $original);

            if ($match !== null) {
                return $match;
            }
        }

        return $this->outbox->senders($user)[0]
            ?? throw new RuntimeException('No hay una cuenta de Gmail con permiso para enviar. Actívalo en Configuración y vuelve a conectarla.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function originalRef(User $user, array $payload, MailSender $sender): ?string
    {
        $original = (string) ($payload['original'] ?? '');

        if ($original !== '' && str_contains($original, '.')) {
            return $original;
        }

        $messageId = (string) ($payload['in_reply_to'] ?? $payload['external_id'] ?? '');

        if ($messageId === '' || str_contains($messageId, '@') || str_starts_with($messageId, '<')) {
            return $original !== '' ? $original : null;
        }

        if (str_contains($messageId, '.')) {
            return $messageId;
        }

        return $sender->accountId.'.'.$messageId;
    }

    private function header(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (str_contains($value, '@') || str_starts_with($value, '<')) {
            return $value;
        }

        return null;
    }

    private function threadId(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return str_contains($value, '.') ? (string) Str::after($value, '.') : $value;
    }
}
