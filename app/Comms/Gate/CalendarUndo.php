<?php

namespace App\Comms\Gate;

use App\Comms\Gate\WriteTools\CreateCalendarEvent;
use App\Models\Comms\CommsProposal;
use Carbon\CarbonImmutable;

/**
 * A created event can be removed for a few minutes after it was written.
 */
class CalendarUndo
{
    public function __construct(private CreateCalendarEvent $calendar) {}

    public function open(CommsProposal $proposal): bool
    {
        if ($proposal->action_class !== ActionClass::CalendarCreate || $proposal->executed_at === null) {
            return false;
        }

        return CarbonImmutable::parse($proposal->executed_at)->greaterThan(CarbonImmutable::now()->subMinutes(5));
    }

    public function delete(CommsProposal $proposal): void
    {
        $this->calendar->delete($proposal);
    }
}
