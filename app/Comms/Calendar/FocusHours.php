<?php

namespace App\Comms\Calendar;

use App\Models\Comms\CommsCalendarEvent;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A focus block on the calendar. Urgent items still come through.
 */
class FocusHours
{
    public function covers(User $user, CarbonImmutable $moment): bool
    {
        return CommsCalendarEvent::query()
            ->whereBelongsTo($user)
            ->where('kind', 'focus')
            ->where('status', 'confirmed')
            ->where('starts_at', '<=', $moment)
            ->where('ends_at', '>', $moment)
            ->exists();
    }
}
