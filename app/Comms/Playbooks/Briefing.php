<?php

namespace App\Comms\Playbooks;

use App\Comms\Notify\Notifier;
use App\Models\Comms\CommsCalendarEvent;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsQuestion;
use App\Models\User;

/**
 * A meeting is briefed only when something is open. Interview feedback
 * is a reminder for the user and never a message to the recruiter.
 */
class Briefing
{
    public function __construct(private Notifier $notifier) {}

    public function apply(CommsEvent $event): void
    {
        $user = $event->user;
        $meetingId = $event->normalized['calendar_id'] ?? null;

        if ($user === null || ! is_numeric($meetingId)) {
            return;
        }

        $meeting = CommsCalendarEvent::query()->whereBelongsTo($user)->find((int) $meetingId);

        if ($meeting === null) {
            return;
        }

        if (($event->normalized['reason'] ?? '') === 'interview_feedback') {
            $this->tell($user, $event, 'Prepara el feedback de '.$meeting->title.'. Solo para ti.');

            return;
        }

        $open = CommsIntent::query()->whereBelongsTo($user)->where('state', 'open')->get();

        if ($open->isEmpty()) {
            return;
        }

        $this->tell($user, $event, 'Antes de '.$meeting->title.': '.$open->pluck('what')->implode('. '));
    }

    private function tell(User $user, CommsEvent $event, string $text): void
    {
        $question = CommsQuestion::query()->updateOrCreate(
            ['user_id' => $user->id, 'key' => 'q:brief:'.$event->id],
            [
                'intent_id' => null,
                'text' => $text,
                'context' => ['draft' => ''],
                'options' => [],
            ],
        );

        $this->notifier->store($user, 'question', 'now', 'question', $question->id, 'n:brief:'.$event->id, [$event->id]);
    }
}
