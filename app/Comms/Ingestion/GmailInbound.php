<?php

namespace App\Comms\Ingestion;

use App\Comms\Messages\Party;
use Carbon\CarbonImmutable;

/**
 * One Gmail message as the poller hands it to the normalizer.
 */
final readonly class GmailInbound
{
    /**
     * @param  list<Party>  $to
     * @param  list<string>  $labels
     * @param  list<string>  $attachments
     */
    public function __construct(
        public string $messageId,
        public string $threadId,
        public Party $from,
        public array $to,
        public string $subject,
        public string $body,
        public CarbonImmutable $at,
        public array $labels,
        public bool $mine = false,
        public array $attachments = [],
    ) {}

    public function text(): string
    {
        $body = trim($this->body);

        return $body === '' ? $this->subject : $this->subject."\n\n".$body;
    }
}
