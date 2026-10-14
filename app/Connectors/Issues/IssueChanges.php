<?php

namespace App\Connectors\Issues;

/**
 * Changes to an issue: only what is set changes.
 */
final readonly class IssueChanges
{
    /**
     * @param  ?list<string>  $labels  The labels it ends up with.
     */
    public function __construct(
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $priority = null,
        public ?array $labels = null,
        public ?string $assigneeId = null,
        public ?string $parentKey = null,
        /** By name: replaces the issue's components. */
        public ?array $components = null,
    ) {}

    public function isEmpty(): bool
    {
        return array_filter(get_object_vars($this), fn (mixed $value): bool => $value !== null) === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }
}
