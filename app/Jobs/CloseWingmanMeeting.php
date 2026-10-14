<?php

namespace App\Jobs;

use App\Models\WingmanMeeting;
use App\Wingman\Closing\MeetingCloser;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Closes a call's document: final notes and what is left to do.
 */
class CloseWingmanMeeting implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [20, 90];

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function __construct(public WingmanMeeting $meeting) {}

    public function uniqueId(): string
    {
        return (string) $this->meeting->id;
    }

    public function handle(MeetingCloser $closer): void
    {
        $closer->close($this->meeting->fresh() ?? $this->meeting);
    }
}
