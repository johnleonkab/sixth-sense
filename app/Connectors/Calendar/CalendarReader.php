<?php

namespace App\Connectors\Calendar;

use App\Models\User;

interface CalendarReader
{
    /**
     * Read the user's events from every account allowed to show the calendar.
     */
    public function read(User $user, EventQuery $query): CalendarReading;

    /**
     * One event by its ref, in the given timezone, if the user can see it.
     */
    public function event(User $user, string $ref, string $timezone): ?CalendarEvent;
}
