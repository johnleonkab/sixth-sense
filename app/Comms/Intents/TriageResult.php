<?php

namespace App\Comms\Intents;

/**
 * What triage decided before matching runs.
 */
final readonly class TriageResult
{
    public function __construct(
        public string $label,
        public bool $urgent = false,
        public bool $sensitive = false,
    ) {}

    public function stops(): bool
    {
        return in_array($this->label, ['noise', 'cc_only', 'delivery'], true);
    }
}
