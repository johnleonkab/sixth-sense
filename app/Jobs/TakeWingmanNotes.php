<?php

namespace App\Jobs;

use App\Models\WingmanMeeting;
use App\Wingman\Notes\LiveNotes;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Takes what was just said in a call into its notes.
 */
class TakeWingmanNotes implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [10];

    public int $uniqueFor = 180;

    public function __construct(public WingmanMeeting $meeting) {}

    public function uniqueId(): string
    {
        return (string) $this->meeting->id;
    }

    public function handle(LiveNotes $notes): void
    {
        $notes->take($this->meeting->fresh() ?? $this->meeting);
    }
}
