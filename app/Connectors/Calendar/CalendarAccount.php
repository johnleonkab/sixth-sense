<?php

namespace App\Connectors\Calendar;

/**
 * An account whose calendar the user may change.
 */
final readonly class CalendarAccount
{
    public function __construct(
        public int $accountId,
        public string $email,
    ) {}
}
