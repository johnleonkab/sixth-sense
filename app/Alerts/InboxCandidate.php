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

    /**
     * Plain data, to keep it with the alert while it is judged in the background.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $address = fn (MailAddress $address): array => $address->toArray();

        return [
            'source' => $this->source->value,
            'external_id' => $this->externalId,
            'account' => $this->account,
            'from' => $this->from?->toArray(),
            'to' => array_map($address, $this->to),
            'cc' => array_map($address, $this->cc),
            'subject' => $this->subject,
            'excerpt' => mb_substr($this->excerpt, 0, 4000),
            'received_at' => $this->receivedAt->toIso8601String(),
            'labels' => $this->labels,
            'is_reply' => $this->isReply,
            'is_bulk' => $this->isBulk,
            'url' => $this->url,
            'thread_key' => $this->threadKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $address = fn (array $address): MailAddress => new MailAddress($address['name'] ?? null, $address['email'] ?? null);

        return new self(
            source: AlertSource::from($data['source']),
            externalId: $data['external_id'],
            account: (string) $data['account'],
            from: isset($data['from']) ? $address($data['from']) : null,
            to: array_map($address, $data['to'] ?? []),
            cc: array_map($address, $data['cc'] ?? []),
            subject: (string) $data['subject'],
            excerpt: (string) $data['excerpt'],
            receivedAt: CarbonImmutable::parse($data['received_at']),
            labels: $data['labels'] ?? [],
            isReply: (bool) ($data['is_reply'] ?? false),
            isBulk: (bool) ($data['is_bulk'] ?? false),
            url: $data['url'] ?? null,
            threadKey: $data['thread_key'] ?? null,
        );
    }
}
