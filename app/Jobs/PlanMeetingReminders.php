<?php

namespace App\Jobs;

use App\Alerts\AlertSource;
use App\Alerts\MeetingReminders;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Looks at the user's next meetings and decides when to remind each one.
 */
class PlanMeetingReminders implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public User $user) {}

    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    public function handle(MeetingReminders $reminders): void
    {
        if ($this->user->wantsAlertsFrom(AlertSource::Calendar)) {
            $reminders->plan($this->user);
        }
    }
}
