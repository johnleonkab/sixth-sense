<?php

namespace App\Comms\Linking;

/**
 * Where a segment is placed after the three linker runs. Never "unplaced" (D3).
 */
final readonly class LinkOutcome
{
    /**
     * @param  string  $target  `intent:{id}`, `topic:{subject}`, `new_topic` or `none`
     * @param  '3of3'|'2of3'|'none'  $agreement
     * @param  list<int>  $runEventIds
     */
    public function __construct(
        public string $target,
        public ?string $topicSubject,
        public LinkCertainty $certainty,
        public string $agreement,
        public ?string $alternative,
        public string $quote,
        public ?string $newTopicName,
        public array $runEventIds,
    ) {}

    public function intentId(): ?int
    {
        return str_starts_with($this->target, 'intent:') ? (int) substr($this->target, 7) : null;
    }

    public function isNone(): bool
    {
        return $this->target === 'none';
    }

    /** The value stored in `comms_link_decisions.outcome`. */
    public function outcomeColumn(): string
    {
        return match (true) {
            str_starts_with($this->target, 'intent:') => 'topic_intent',
            str_starts_with($this->target, 'topic:') => 'topic_new_intent',
            $this->target === 'new_topic' => 'new_topic',
            default => 'none',
        };
    }
}
