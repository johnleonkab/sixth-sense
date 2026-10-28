<?php

namespace App\Analytics;

use App\Models\User;

/**
 * Keeps the events a test captured, after the same whitelist as production.
 */
class RecordingProductAnalytics implements ProductAnalytics
{
    /**
     * @var list<array{event: string, distinct_id: string, properties: array<string, bool|int|string>}>
     */
    public array $events = [];

    public function capture(User $user, SlackTriageEvent $event, array $properties = []): void
    {
        $this->events[] = [
            'event' => $event->value,
            'distinct_id' => (string) $user->id,
            'properties' => SlackTriageEvent::properties($properties),
        ];
    }
}
