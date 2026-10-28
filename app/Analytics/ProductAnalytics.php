<?php

namespace App\Analytics;

use App\Models\User;

/**
 * The only way the Slack triage reports what it did. A missing or disabled
 * PostHog project does not stop the triage.
 */
interface ProductAnalytics
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function capture(User $user, SlackTriageEvent $event, array $properties = []): void;
}
