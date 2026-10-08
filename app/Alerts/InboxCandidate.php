<?php

namespace App\Alerts;

use App\Connectors\Mail\MailAddress;
use Carbon\CarbonImmutable;

/**
 * Something new that may deserve an alert, normalised from its source: an
 * email, or a Slack direct message or mention.
 */
final readonly class InboxCandidate
{
    /**
     * @param  string  $externalId  Unique within the source: the message ref.
     * @param  ?string  $subject  For Slack, where it was said ("Mensaje directo", "#producto").
     * @param  list<MailAddress>  $to
     * @param  list<MailAddress>  $cc
     * @param  list<string>  $labels  Provider labels, e.g. INBOX, CATEGORY_PROMOTIONS; for Slack: dm, group or mention.
     * @param  ?string  $threadKey  The conversation it belongs to: further messages there update the same alert.
     */
    public function __construct(
        public AlertSource $source,
        public string $externalId,
        public string $account,
        public ?MailAddress $from,
        public array $to,
        public array $cc,
        public string $subject,
        public string $excerpt,
        public CarbonImmutable $receivedAt,
        public array $labels = [],
        public bool $isReply = false,
        public bool $isBulk = false,
        public ?string $url = null,
        public ?string $threadKey = null,
    ) {}
}
