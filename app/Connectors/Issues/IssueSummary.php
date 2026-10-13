<?php

namespace App\Connectors\Issues;

use Carbon\CarbonImmutable;

/**
 * An issue as a list shows it.
 */
final readonly class IssueSummary
{
    public function __construct(
        public string $ref,
        public string $key,
        public string $summary,
        public string $type,
        public string $status,
        public string $statusCategory,
        public ?string $priority,
        public ?string $assignee,
        public ?CarbonImmutable $updatedAt,
        public ?string $url,
        public string $site,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $timezone): array
    {
        return [
            'ref' => $this->ref,
            'key' => $this->key,
            'summary' => $this->summary,
            'type' => $this->type,
            'status' => $this->status,
            'status_category' => $this->statusCategory,
            'priority' => $this->priority,
            'assignee' => $this->assignee,
            'updated_at' => $this->updatedAt?->setTimezone($timezone)->toIso8601String(),
            'url' => $this->url,
            'site' => $this->site,
        ];
    }
}
