<?php

namespace App\Connectors\Calendar;

/**
 * Events read across every account that can show the calendar.
 */
final readonly class CalendarReading
{
    /**
     * @param  list<CalendarEvent>  $events  Sorted by start.
     * @param  list<string>  $accounts  Accounts that were read.
     * @param  list<string>  $failedAccounts  Accounts that could not be read.
     * @param  list<array{account: string, name: string, color: ?string}>  $calendars  Calendars that were read.
     */
    public function __construct(
        public array $events,
        public array $accounts,
        public array $failedAccounts = [],
        public array $calendars = [],
    ) {}
}
