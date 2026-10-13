<?php

namespace App\Connectors\Issues;

use Carbon\CarbonImmutable;

/**
 * A comment on an issue. Jira comments are flat: an answer is a new comment
 * that quotes and mentions the one it answers.
 */
final readonly class IssueComment
{
    /**
     * @param  list<array<string, mixed>>  $blocks  To render it.
     */
    public function __construct(
        public string $ref,
        public string $id,
        public string $author,
        public ?string $authorId,
        public bool $mine,
        public string $text,
        public array $blocks,
        public CarbonImmutable $createdAt,
        public bool $mentionsMe = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $timezone, int $number): array
    {
        return [
            'number' => $number,
            'ref' => $this->ref,
            'author' => $this->author,
            'mine' => $this->mine,
            'text' => $this->text,
            'blocks' => $this->blocks,
            'created_at' => $this->createdAt->setTimezone($timezone)->toIso8601String(),
            'mentions_me' => $this->mentionsMe,
        ];
    }
}
