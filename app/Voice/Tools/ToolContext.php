<?php

namespace App\Voice\Tools;

use Carbon\CarbonImmutable;

/**
 * Where and when a tool runs: the user's current moment and timezone, and who
 * called it — the voice model, an agent, or the person from the screen.
 */
final readonly class ToolContext
{
    public const VOICE = 'voice';

    public const AGENT = 'agent';

    public const SCREEN = 'screen';

    public function __construct(
        public CarbonImmutable $now,
        public string $origin = self::SCREEN,
    ) {}

    /**
     * A model chose the arguments (the voice or an agent), not the person.
     */
    public function byModel(): bool
    {
        return $this->origin !== self::SCREEN;
    }

    public function timezone(): string
    {
        return $this->now->getTimezone()->getName();
    }
}
