<?php

namespace App\Wingman\Closing;

use App\Models\WingmanMeeting;
use App\Wingman\Notes\MeetingNotes;

/**
 * At the end of a call, says what the person has left to do.
 */
interface FollowUpPlanner
{
    /**
     * @param  list<string>  $prepared  What is already prepared, not to propose again.
     * @return list<FollowUp>
     */
    public function plan(WingmanMeeting $meeting, MeetingNotes $notes, array $prepared): array;
}
