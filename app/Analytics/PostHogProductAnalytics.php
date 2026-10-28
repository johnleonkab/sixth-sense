<?php

namespace App\Analytics;

use App\Models\User;
use App\Services\PostHogService;

/**
 * Sends a triage event through the PostHog client the wizard installed.
 * Properties are reduced to the whitelist first.
 */
class PostHogProductAnalytics implements ProductAnalytics
{
    public function __construct(private PostHogService $posthog) {}

    public function capture(User $user, SlackTriageEvent $event, array $properties = []): void
    {
        $this->posthog->capture((string) $user->id, $event->value, SlackTriageEvent::properties($properties));
    }
}
