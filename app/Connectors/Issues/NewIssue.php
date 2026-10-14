<?php

namespace App\Connectors\Issues;

/**
 * An issue to create: an epic, a story, a task, a subtask…
 */
final readonly class NewIssue
{
    /**
     * @param  ?string  $parentKey  The epic of a story, or the story of a subtask.
     * @param  list<string>  $labels
     * @param  list<string>  $components  By name; some projects require at least one.
     */
    public function __construct(
        public string $projectKey,
        public string $type,
        public string $summary,
        public ?string $description = null,
        public ?string $parentKey = null,
        public ?string $priority = null,
        public array $labels = [],
        public ?string $assigneeId = null,
        public array $components = [],
    ) {}

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
