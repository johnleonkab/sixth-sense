<?php

namespace App\Connectors\Mail;

/**
 * An account the user can send from.
 */
final readonly class MailSender
{
    public function __construct(
        public int $accountId,
        public string $email,
    ) {}
}
