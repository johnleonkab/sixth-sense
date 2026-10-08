<?php

namespace App\Connectors\Mail;

use Carbon\CarbonImmutable;

/**
 * An email from any connected account. `ref` identifies it across accounts.
 */
final readonly class MailMessage
{
    /**
     * @param  list<MailAddress>  $to
     * @param  list<MailAddress>  $cc
     * @param  list<array{name: string, mime_type: ?string, size: int, id?: ?string}>  $attachments
     * @param  list<MailAddress>  $replyTo
     * @param  ?string  $messageId  The Message-ID header, to thread replies.
     * @param  ?string  $references  The References header, to thread replies.
     */
    public function __construct(
        public string $ref,
        public string $account,
        public ?MailAddress $from,
        public array $to,
        public string $subject,
        public CarbonImmutable $date,
        public string $snippet,
        public bool $unread = false,
        public ?string $body = null,
        public array $cc = [],
        public array $attachments = [],
        public ?string $url = null,
        public ?string $threadId = null,
        public ?string $messageId = null,
        public ?string $references = null,
        public array $replyTo = [],
    ) {}
}
