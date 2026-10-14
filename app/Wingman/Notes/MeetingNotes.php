<?php

namespace App\Wingman\Notes;

use Illuminate\Support\Str;

/**
 * The living notes of a call: a short summary, the topics with their
 * points, what was decided, what is still open, and who committed to what.
 */
final readonly class MeetingNotes
{
    private const MAX_TOPICS = 12;

    private const MAX_POINTS = 8;

    private const MAX_ITEMS = 15;

    /**
     * @param  list<array{title: string, points: list<string>}>  $topics
     * @param  list<string>  $decisions
     * @param  list<string>  $questions
     * @param  list<array{who: string, what: string, when: ?string}>  $commitments  "Tú" when it is the person.
     */
    public function __construct(
        public string $summary = '',
        public array $topics = [],
        public array $decisions = [],
        public array $questions = [],
        public array $commitments = [],
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * From what was kept or what the note-taker answered, trimmed to sane sizes.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $strings = fn (mixed $items, int $limit): array => array_slice(array_values(array_filter(array_map(
            fn (mixed $item): string => is_string($item) ? Str::limit(trim($item), 400) : '',
            is_array($items) ? $items : [],
        ))), 0, $limit);

        return new self(
            summary: Str::limit(trim((string) ($data['summary'] ?? '')), 800),
            topics: array_slice(array_values(array_filter(array_map(fn (mixed $topic): ?array => is_array($topic) && filled($topic['title'] ?? null) ? [
                'title' => Str::limit(trim((string) $topic['title']), 120),
                'points' => $strings($topic['points'] ?? [], self::MAX_POINTS),
            ] : null, is_array($data['topics'] ?? null) ? $data['topics'] : []))), 0, self::MAX_TOPICS),
            decisions: $strings($data['decisions'] ?? [], self::MAX_ITEMS),
            questions: $strings($data['questions'] ?? [], self::MAX_ITEMS),
            commitments: array_slice(array_values(array_filter(array_map(fn (mixed $commitment): ?array => is_array($commitment) && filled($commitment['what'] ?? null) ? [
                'who' => Str::limit(trim((string) ($commitment['who'] ?? '')), 80),
                'what' => Str::limit(trim((string) $commitment['what']), 300),
                'when' => filled($commitment['when'] ?? null) ? Str::limit(trim((string) $commitment['when']), 80) : null,
            ] : null, is_array($data['commitments'] ?? null) ? $data['commitments'] : []))), 0, self::MAX_ITEMS),
        );
    }

    /**
     * @return array{summary: string, topics: list<array{title: string, points: list<string>}>, decisions: list<string>, questions: list<string>, commitments: list<array{who: string, what: string, when: ?string}>}
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'topics' => $this->topics,
            'decisions' => $this->decisions,
            'questions' => $this->questions,
            'commitments' => $this->commitments,
        ];
    }

    /**
     * How many notes it holds, for the bubble.
     */
    public function count(): int
    {
        return array_sum(array_map(fn (array $topic): int => count($topic['points']), $this->topics))
            + count($this->decisions) + count($this->questions) + count($this->commitments);
    }
}
