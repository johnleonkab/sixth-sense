<?php

namespace App\Wingman;

enum MeetingStatus: string
{
    /** In the calendar, not started. */
    case Scheduled = 'scheduled';

    /** The person is in the call. */
    case Live = 'live';

    /** The person left the call. */
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Programada',
            self::Live => 'En curso',
            self::Ended => 'Terminada',
        };
    }
}
