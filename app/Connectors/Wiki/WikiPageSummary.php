<?php

namespace App\Connectors\Wiki;

use Carbon\CarbonImmutable;

/**
 * A page as search results list it.
 */
final readonly class WikiPageSummary
{
    public function __construct(
        public string $ref,
        public string $title,
        public ?string $icon,
        public ?string $url,
        public string $workspace,
        public ?CarbonImmutable $editedAt = null,
        public ?string $parentTitle = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $timezone): array
    {
        return [
            'ref' => $this->ref,
            'title' => $this->title,
            'icon' => $this->icon,
            'url' => $this->url,
            'workspace' => $this->workspace,
            'edited_at' => $this->editedAt?->setTimezone($timezone)->toIso8601String(),
            'parent' => $this->parentTitle,
        ];
    }
}
