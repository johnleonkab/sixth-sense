<?php

namespace App\Jobs;

use App\Alerts\Lessons\Lessons;
use App\Alerts\Lessons\LessonWriter;
use App\Models\ProposalOutcome;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Turns one correction of the person into lessons the next proposals read.
 */
class LearnFromOutcome implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(public ProposalOutcome $outcome) {}

    public function handle(LessonWriter $writer, Lessons $lessons): void
    {
        $outcome = $this->outcome->fresh();

        // Undone in the meantime, or already learned: nothing to learn.
        if ($outcome === null || $outcome->learned_at !== null || $outcome->weight() !== ProposalOutcome::HIGH) {
            return;
        }

        foreach ($writer->write($outcome) as $lesson) {
            $lessons->learn($outcome->user, $lesson['body'], $lesson['layer'], $lesson['about'], $lesson['topic'], outcome: $outcome);
        }

        $outcome->update(['learned_at' => now()]);
    }
}
