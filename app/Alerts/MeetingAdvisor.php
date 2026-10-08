<?php

namespace App\Alerts;

use App\Connectors\Calendar\CalendarEvent;
use App\Models\User;

/**
 * Decides whether a meeting needs preparation and how early to remind.
 */
interface MeetingAdvisor
{
    /**
     * @throws \Throwable when it could not decide; the meeting is looked at again later.
     */
    public function advise(User $user, CalendarEvent $event): MeetingBriefing;
}
