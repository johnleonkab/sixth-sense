<?php

namespace App\Jobs;

use App\Comms\Context\ContextWriter;
use App\Comms\Context\RuleWriter;
use App\Models\ProposalOutcome;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Turns one correction of the person into rules the next proposals read.
 */
class LearnFromOutcome implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(public ProposalOutcome $outcome) {}

    public function handle(RuleWriter $writer, ContextWriter $context): void
    {
        $outcome = $this->outcome->fresh();

        // Undone in the meantime, or already learned: nothing to learn.
        if ($outcome === null || $outcome->learned_at !== null || $outcome->weight() !== ProposalOutcome::HIGH) {
            return;
        }

        foreach ($writer->write($outcome) as $lesson) {
            $context->rule($outcome->user, $lesson['body'], $lesson['layer'], $lesson['about'], $lesson['topic'], ["outcome:{$outcome->id}"]);
        }

        $outcome->update(['learned_at' => now()]);
    }
}
