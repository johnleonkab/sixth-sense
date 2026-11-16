<?php

namespace App\Comms\Linking;

/**
 * One linker run, parsed from its schema-valid output. An invalid run is a
 * vote with `valid = false`: it counts as a disagreeing run.
 *
 * Targets are keys, so votes compare as strings:
 *  - `intent:{id}`               an existing intent (inside `topicSubject`)
 *  - `topic:{subject}`           the topic, with no existing intent (a new intent opens there)
 *  - `new_topic`                 a new topic
 *  - `none`                      nothing worth tracking
 */
final readonly class LinkVote
{
    public function __construct(
        public bool $valid,
        public ?string $target = null,
        public ?string $topicSubject = null,
        public ?string $alternative = null,
        public string $quote = '',
        public ?string $newTopicName = null,
        public ?int $decisionEventId = null,
    ) {}

    public static function invalid(?int $decisionEventId = null): self
    {
        return new self(valid: false, decisionEventId: $decisionEventId);
    }

    /**
     * Builds a vote from the `link` schema output (Schemas::link()).
     * `ambiguous(a, b)` counts as a vote for `a`, with `b` kept as the alternative.
     *
     * @param  array<string, mixed>  $output
     */
    public static function fromOutput(array $output, ?int $decisionEventId = null): self
    {
        $choice = LinkChoice::tryFrom((string) ($output['decision'] ?? ''));
        $quote = trim((string) ($output['quote'] ?? ''));

        return match ($choice) {
            LinkChoice::Topic => self::topic($output, $quote, $decisionEventId),
            LinkChoice::Ambiguous => filled($output['a'] ?? null)
                ? new self(true, (string) $output['a'], self::subjectOf((string) $output['a'], $output), filled($output['b'] ?? null) ? (string) $output['b'] : null, $quote, decisionEventId: $decisionEventId)
                : self::invalid($decisionEventId),
            LinkChoice::NewTopic => new self(true, 'new_topic', null, null, $quote, filled($output['name'] ?? null) ? (string) $output['name'] : null, $decisionEventId),
            LinkChoice::None => new self(true, 'none', null, null, $quote, decisionEventId: $decisionEventId),
            null => self::invalid($decisionEventId),
        };
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private static function topic(array $output, string $quote, ?int $decisionEventId): self
    {
        $subject = (string) ($output['topic_id'] ?? '');

        if ($subject === '') {
            return self::invalid($decisionEventId);
        }

        $intent = $output['intent'] ?? [];
        $intentId = is_array($intent) && ($intent['decision'] ?? null) === 'existing' && filled($intent['intent_id'] ?? null)
            ? (string) $intent['intent_id']
            : null;

        return new self(true, $intentId !== null ? "intent:{$intentId}" : "topic:{$subject}", $subject, null, $quote, decisionEventId: $decisionEventId);
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private static function subjectOf(string $target, array $output): ?string
    {
        if (str_starts_with($target, 'topic:')) {
            return substr($target, 6);
        }

        return filled($output['topic_id'] ?? null) ? (string) $output['topic_id'] : null;
    }
}
