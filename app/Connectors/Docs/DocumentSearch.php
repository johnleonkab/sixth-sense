<?php

namespace App\Connectors\Docs;

use Carbon\CarbonImmutable;

final readonly class DocumentSearch
{
    public function __construct(
        public ?string $name = null,
        public ?string $text = null,
        public ?string $owner = null,
        public ?CarbonImmutable $modifiedAfter = null,
        public ?CarbonImmutable $modifiedBefore = null,
        public ?CarbonImmutable $createdAfter = null,
        public ?CarbonImmutable $createdBefore = null,
        public int $limit = 15,
    ) {}

    /**
     * @return array<string, string>
     */
    public function describe(): array
    {
        return array_filter([
            'name' => $this->name,
            'text' => $this->text,
            'owner' => $this->owner,
            'modified_after' => $this->modifiedAfter?->toDateString(),
            'modified_before' => $this->modifiedBefore?->toDateString(),
            'created_after' => $this->createdAfter?->toDateString(),
            'created_before' => $this->createdBefore?->toDateString(),
        ], fn (?string $value): bool => filled($value));
    }
}
