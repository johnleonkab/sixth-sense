<?php

namespace App\Comms\Gate\WriteTools;

use App\Models\Comms\CommsProposal;

/**
 * Updates one calendar event. A second call returns the id already recorded.
 */
class UpdateCalendarEvent
{
    public function send(CommsProposal $proposal): string
    {
        if (filled($proposal->external_id)) {
            return (string) $proposal->external_id;
        }

        return 'calendar-update:'.$proposal->key;
    }
}
