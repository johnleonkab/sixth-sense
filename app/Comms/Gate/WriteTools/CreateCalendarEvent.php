<?php

namespace App\Comms\Gate\WriteTools;

use App\Models\Comms\CommsProposal;

/**
 * Creates one calendar event. A second call returns the id already recorded.
 * Delete marks the event removed. Nothing here calls a calendar API.
 */
class CreateCalendarEvent
{
    public function send(CommsProposal $proposal): string
    {
        if (filled($proposal->external_id)) {
            return (string) $proposal->external_id;
        }

        return 'calendar:'.$proposal->key;
    }

    public function delete(CommsProposal $proposal): void
    {
        $payload = $proposal->payload;
        $payload['deleted'] = true;
        $proposal->payload = $payload;
        $proposal->save();
    }
}
