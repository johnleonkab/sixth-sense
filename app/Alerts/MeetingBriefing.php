<?php

namespace App\Alerts;

/**
 * How early to remind the user about a meeting, and why.
 */
final readonly class MeetingBriefing
{
    public const MIN_LEAD = 1;

    public const MAX_LEAD = 30;

    /**
     * @param  string  $preparation  What to prepare, or a short note when nothing is needed.
     */
    public function __construct(
        public bool $needsPreparation,
        public int $leadMinutes,
        public string $preparation,
    ) {}

    public static function make(bool $needsPreparation, int $leadMinutes, string $preparation): self
    {
        return new self($needsPreparation, max(self::MIN_LEAD, min(self::MAX_LEAD, $leadMinutes)), trim($preparation));
    }
}
