<?php

namespace App\Voice\Tools;

use Carbon\CarbonImmutable;

/**
 * Where and when a tool runs: the user's current moment and timezone.
 */
final readonly class ToolContext
{
    public function __construct(
        public CarbonImmutable $now,
    ) {}

    public function timezone(): string
    {
        return $this->now->getTimezone()->getName();
    }
}
