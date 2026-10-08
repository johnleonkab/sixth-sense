<?php

namespace App\Connectors\Mail;

use App\Models\User;

interface Outbox
{
    /**
     * Accounts the user can send from, the oldest first.
     *
     * @return list<MailSender>
     */
    public function senders(User $user): array;

    /**
     * The account an email was received in, if the user can send from it:
     * replies and forwards go out from there.
     */
    public function senderFor(User $user, string $originalRef): ?MailSender;

    /**
     * Send the email as the user. Returns the sent message's ref.
     *
     * @throws \Throwable when it could not be sent; the message is shown to the person.
     */
    public function send(User $user, OutgoingEmail $email): string;

    /**
     * Where the person can see the sent email.
     */
    public function urlFor(User $user, OutgoingEmail $email, string $sentRef): ?string;
}
