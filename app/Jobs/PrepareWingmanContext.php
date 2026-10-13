<?php

namespace App\Jobs;

use App\Models\WingmanMeeting;
use App\Wingman\MeetingContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Gathers what the person should have at hand before a call.
 */
class PrepareWingmanContext implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 90;

    public int $uniqueFor = 300;

    public function __construct(public WingmanMeeting $meeting) {}

    public function uniqueId(): string
    {
        return (string) $this->meeting->id;
    }

    public function handle(MeetingContext $context): void
    {
        $context->prepare($this->meeting->fresh() ?? $this->meeting);
    }
}
