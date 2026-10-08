<?php

namespace App\Connectors\Mail;

final readonly class FoundMessage
{
    /**
     * @param  ?array{before: string, match: string, after: string}  $excerpt  Where the pattern matched.
     */
    public function __construct(
        public MailMessage $message,
        public ?array $excerpt = null,
    ) {}
}
