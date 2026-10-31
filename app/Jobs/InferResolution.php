<?php

namespace App\Jobs;

use App\Alerts\Outcomes\ResolutionInference;
use App\Models\ProposalOutcome;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Works out, without hurry, how an alert the person marked as resolved was
 * resolved.
 */
class InferResolution implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $outcomeId)
    {
        $this->delay(now()->addMinutes(2));
    }

    public function handle(ResolutionInference $inference): void
    {
        $outcome = ProposalOutcome::query()->with('inboxItem.user')->find($this->outcomeId);

        if ($outcome !== null) {
            $inference->infer($outcome);
        }
    }
}
