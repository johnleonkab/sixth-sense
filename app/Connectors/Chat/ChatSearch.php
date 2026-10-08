<?php

namespace App\Connectors\Chat;

use Carbon\CarbonImmutable;

final readonly class ChatSearch
{
    public function __construct(
        public ?string $text = null,
        public ?string $from = null,
        public ?string $in = null,
        public ?CarbonImmutable $after = null,
        public ?CarbonImmutable $before = null,
        public int $limit = 15,
    ) {}

    /**
     * @return array<string, string>
     */
    public function describe(): array
    {
        return array_filter([
            'text' => $this->text,
            'from' => $this->from,
            'in' => $this->in,
            'after' => $this->after?->toDateString(),
            'before' => $this->before?->toDateString(),
        ], fn (?string $value): bool => filled($value));
    }
}
