<?php

namespace App\Comms\Gate\WriteTools;

use App\Comms\Gate\ActionClass;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\Outbox;
use App\Connectors\Mail\OutgoingEmail;
use App\Models\Comms\CommsProposal;
use App\Models\User;

/**
 * Sends a reply or a new email. Headers for a reply come from the thread.
 */
class SendEmail
{
    public function __construct(private Outbox $outbox) {}

    public function send(User $user, CommsProposal $proposal): string
    {
        if (filled($proposal->external_id)) {
            return $proposal->external_id;
        }

        $payload = $proposal->payload;
        $reply = $proposal->action_class === ActionClass::SendEmailReply;

        return $this->outbox->send($user, new OutgoingEmail(
            mode: $reply ? 'reply' : 'new',
            accountId: (int) ($payload['account_id'] ?? 0),
            from: (string) ($payload['from'] ?? 'me@example.com'),
            to: [new MailAddress((string) ($payload['to_name'] ?? $payload['to_email']), (string) $payload['to_email'])],
            cc: [],
            subject: (string) ($payload['subject'] ?? ''),
            body: (string) ($payload['text'] ?? ''),
            threadId: isset($payload['thread_id']) ? (string) $payload['thread_id'] : null,
            inReplyTo: isset($payload['in_reply_to']) ? (string) $payload['in_reply_to'] : null,
            references: isset($payload['references']) ? (string) $payload['references'] : null,
        ));
    }
}
