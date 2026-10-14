<?php

namespace App\Wingman\Notes;

use App\Models\WingmanMeeting;

/**
 * Keeps a call's notes up to date as it goes.
 */
interface NoteTaker
{
    /**
     * The notes with what was just said taken in.
     *
     * @param  list<array{at: string, speaker: string, is_me: bool, text: string}>  $lines  Oldest first.
     */
    public function update(WingmanMeeting $meeting, MeetingNotes $notes, array $lines): MeetingNotes;
}
