<?php

namespace App\Jobs;

use App\Models\User;
use App\Wingman\WingmanMeetings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Finds today's Meet calls of a person in their calendars.
 */
class SyncWingmanMeetings implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(public User $user) {}

    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    public function handle(WingmanMeetings $meetings): void
    {
        $meetings->sync($this->user);
    }
}
