<?php

namespace App\Connectors\Docs;

use Carbon\CarbonImmutable;

/**
 * A comment thread on a document: the comment, the text it points at, and its replies.
 */
final readonly class DocumentComment
{
    /**
     * @param  list<array{id: string, author: string, mine: bool, content: string, created_at: CarbonImmutable}>  $replies  Oldest first.
     */
    public function __construct(
        public string $id,
        public string $author,
        public bool $mine,
        public string $content,
        public ?string $quote,
        public CarbonImmutable $createdAt,
        public bool $resolved = false,
        public array $replies = [],
    ) {}

    /**
     * Whether the last word is someone else's: it may be waiting for the user.
     */
    public function awaitsUser(): bool
    {
        $last = $this->replies === [] ? ['mine' => $this->mine] : $this->replies[array_key_last($this->replies)];

        return ! $this->resolved && ! $last['mine'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $timezone, int $number): array
    {
        return [
            'number' => $number,
            'id' => $this->id,
            'author' => $this->author,
            'mine' => $this->mine,
            'content' => $this->content,
            'quote' => $this->quote,
            'created_at' => $this->createdAt->setTimezone($timezone)->toIso8601String(),
            'resolved' => $this->resolved,
            'awaits_user' => $this->awaitsUser(),
            'replies' => array_map(fn (array $reply): array => [
                ...$reply,
                'created_at' => $reply['created_at']->setTimezone($timezone)->toIso8601String(),
            ], $this->replies),
        ];
    }
}
