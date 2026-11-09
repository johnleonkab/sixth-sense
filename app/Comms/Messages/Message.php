<?php

namespace App\Comms\Messages;

use App\Comms\Events\Source;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * One thing a person said, on one channel, after normalization.
 */
final readonly class Message
{
    /**
     * @param  list<Party>  $recipients
     * @param  list<string>  $links
     * @param  list<string>  $attachments
     */
    public function __construct(
        public string $externalId,
        public Source $source,
        public string $channel,
        public ?string $thread,
        public Party $author,
        public array $recipients,
        public Direction $direction,
        public CarbonImmutable $at,
        public string $text,
        public array $links = [],
        public array $attachments = [],
        public bool $sensitive = false,
        public bool $automated = false,
    ) {}

    /**
     * @return list<string>
     */
    public static function linksIn(string $text): array
    {
        preg_match_all('#https?://[^\s<>]+#', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    /**
     * @return array{
     *     external_id: string,
     *     source: string,
     *     channel: string,
     *     thread: ?string,
     *     author: array{external_id: string, email: ?string, display_name: string, bot: bool, handle: ?string},
     *     recipients: list<array{external_id: string, email: ?string, display_name: string, bot: bool, handle: ?string}>,
     *     direction: string,
     *     at: string,
     *     text: string,
     *     links: list<string>,
     *     attachments: list<string>,
     *     sensitive: bool,
     *     automated: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'source' => $this->source->value,
            'channel' => $this->channel,
            'thread' => $this->thread,
            'author' => $this->author->toArray(),
            'recipients' => array_map(fn (Party $party): array => $party->toArray(), $this->recipients),
            'direction' => $this->direction->value,
            'at' => $this->at->toIso8601String(),
            'text' => $this->text,
            'links' => $this->links,
            'attachments' => $this->attachments,
            'sensitive' => $this->sensitive,
            'automated' => $this->automated,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $at = $data['at'] ?? null;

        return new self(
            externalId: (string) $data['external_id'],
            source: $data['source'] instanceof Source ? $data['source'] : Source::from((string) $data['source']),
            channel: (string) $data['channel'],
            thread: isset($data['thread']) ? (string) $data['thread'] : null,
            author: self::party($data['author'] ?? ''),
            recipients: array_map(fn (mixed $recipient): Party => self::party($recipient), array_values($data['recipients'] ?? [])),
            direction: $data['direction'] instanceof Direction ? $data['direction'] : Direction::from((string) $data['direction']),
            at: $at instanceof DateTimeInterface ? CarbonImmutable::instance($at) : CarbonImmutable::parse((string) $at),
            text: (string) $data['text'],
            links: array_values($data['links'] ?? []),
            attachments: array_values($data['attachments'] ?? []),
            sensitive: (bool) ($data['sensitive'] ?? false),
            automated: (bool) ($data['automated'] ?? false),
        );
    }

    private static function party(mixed $value): Party
    {
        if ($value instanceof Party) {
            return $value;
        }

        if (is_array($value)) {
            return Party::fromArray($value);
        }

        return new Party((string) $value, null, (string) $value);
    }
}
