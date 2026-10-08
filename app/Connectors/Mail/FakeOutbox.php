<?php

namespace App\Connectors\Mail;

use App\Models\User;

/**
 * For tests and demo mode: keeps what would have been sent.
 */
class FakeOutbox implements Outbox
{
    /** @var list<OutgoingEmail> */
    public array $sent = [];

    public function senders(User $user): array
    {
        return [new MailSender(0, 'demo@sixth-sense.test')];
    }

    public function senderFor(User $user, string $originalRef): ?MailSender
    {
        return $this->senders($user)[0];
    }

    public function send(User $user, OutgoingEmail $email): string
    {
        $this->sent[] = $email;

        return 'demo.sent-'.count($this->sent);
    }

    public function urlFor(User $user, OutgoingEmail $email, string $sentRef): ?string
    {
        return null;
    }
}
