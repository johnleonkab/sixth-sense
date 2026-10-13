<?php

namespace App\Connectors\Calendar;

use App\Models\User;
use Carbon\CarbonImmutable;

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

    /**
     * When other people are busy, by email, from what their calendars share
     * with the user (usually colleagues in the same organisation). People
     * whose calendar can not be seen are left out.
     *
     * @param  list<string>  $emails
     * @return array<string, list<array{CarbonImmutable, CarbonImmutable}>>
     */
    public function busy(User $user, array $emails, CarbonImmutable $from, CarbonImmutable $to): array;
}
