<?php

namespace App\Alerts\Intents;

/**
 * What a Slack reply or redirect learned before the draft, and the flags
 * PostHog is allowed to keep.
 */
final readonly class SlackDraftBrief
{
    public function __construct(
        public string $findings,
        public string $note,
        public bool $sufficient,
        public bool $agentAsked,
        public bool $conflict,
    ) {}
}
