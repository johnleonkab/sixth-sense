<?php

namespace App\Jobs;

use App\Models\WingmanMeeting;
use App\Wingman\Copilot\WingmanAgent;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Wingman reads what was just said in a call and brings in what helps.
 */
class WatchWingmanCall implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Late is useless in a call: one try, the next reading catches up. */
    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 120;

    public function __construct(public WingmanMeeting $meeting) {}

    public function uniqueId(): string
    {
        return (string) $this->meeting->id;
    }

    public function handle(WingmanAgent $agent): void
    {
        $agent->watch($this->meeting->fresh() ?? $this->meeting);
    }
}
