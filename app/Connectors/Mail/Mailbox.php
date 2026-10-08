<?php

namespace App\Connectors\Mail;

use App\Models\User;

interface Mailbox
{
    /**
     * Search every account allowed to read email.
     */
    public function search(User $user, MailSearch $search): MailSearchResult;

    /**
     * One message with its full body, if it belongs to the user.
     */
    public function message(User $user, string $ref): ?MailMessage;

    /**
     * The bytes of the message's attachment at that position (from 0), as listed in its attachments.
     */
    public function attachment(User $user, string $ref, int $index): ?string;

    /**
     * Whether the user wrote in the message's conversation after it (a reply,
     * from here or from the mail client), so it no longer waits for them.
     */
    public function repliedAfter(User $user, string $ref): bool;
}
