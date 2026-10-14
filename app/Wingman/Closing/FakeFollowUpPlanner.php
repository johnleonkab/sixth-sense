<?php

namespace App\Wingman\Closing;

use App\Models\WingmanMeeting;
use App\Wingman\Notes\MeetingNotes;

/**
 * For tests and demo mode: each commitment of the person becomes a follow-up
 * by simple rules (writing to someone, meeting, an issue, or else a task).
 */
class FakeFollowUpPlanner implements FollowUpPlanner
{
    public function plan(WingmanMeeting $meeting, MeetingNotes $notes, array $prepared): array
    {
        $followUps = [];

        foreach ($notes->commitments as $commitment) {
            if ($commitment['who'] !== 'Tú') {
                continue;
            }

            $what = $commitment['what'];

            $followUps[] = match (true) {
                (bool) preg_match('/\b(?i:escribir|mandar|decir|contar)(?:le)? a (\p{Lu}\p{L}+)/u', $what, $person) => new FollowUp('message', "Escribir a {$person[1]}", ['to' => $person[1], 'about' => $what, 'draft' => "Hola {$person[1]}, como quedamos: {$what}."]),
                (bool) preg_match('/\b(reunión|vernos|quedar)\b/iu', $what) => new FollowUp('meeting', $what, ['title' => "Seguimiento: {$meeting->title}", 'duration_minutes' => 30]),
                (bool) preg_match('/\b(ticket|jira|historia)\b/iu', $what) => new FollowUp('issue', $what, ['summary' => $what, 'description' => "De la reunión «{$meeting->title}»."]),
                default => new FollowUp('task', $what, ['title' => $what, 'context' => "De la reunión «{$meeting->title}»."]),
            };
        }

        return $followUps;
    }
}
