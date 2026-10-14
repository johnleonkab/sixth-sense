<?php

namespace App\Wingman\Copilot;

/**
 * Something the detector noticed in what was just said, before it is checked:
 * talk of finding a slot, an issue or a page named, the person promising a message.
 */
final readonly class Finding
{
    public const KINDS = ['slot', 'issue', 'page', 'message'];

    /**
     * @param  string  $kind  slot, issue, page or message.
     * @param  string  $quote  What was said that led to it.
     * @param  float  $confidence  0 to 1.
     * @param  array<string, mixed>  $details  slot: title, duration_minutes, from_date, to_date; issue and page: query; message: to, about, draft, channel.
     */
    public function __construct(
        public string $kind,
        public string $quote,
        public float $confidence,
        public bool $important,
        public array $details,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        if (! in_array($data['kind'] ?? null, self::KINDS, true)) {
            return null;
        }

        return new self(
            kind: $data['kind'],
            quote: trim((string) ($data['quote'] ?? '')),
            confidence: max(0.0, min(1.0, (float) ($data['confidence'] ?? 0))),
            important: ($data['importance'] ?? 'normal') === 'high',
            details: array_filter([
                'title' => $data['title'] ?? null,
                'duration_minutes' => isset($data['duration_minutes']) ? (int) $data['duration_minutes'] : null,
                'from_date' => $data['from_date'] ?? null,
                'to_date' => $data['to_date'] ?? null,
                'query' => $data['query'] ?? null,
                'to' => $data['to'] ?? null,
                'about' => $data['about'] ?? null,
                'draft' => $data['draft'] ?? null,
                'channel' => $data['channel'] ?? null,
            ], fn (mixed $value): bool => filled($value)),
        );
    }
}
