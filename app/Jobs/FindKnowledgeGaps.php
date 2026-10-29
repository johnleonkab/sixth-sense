<?php

namespace App\Jobs;

use App\Alerts\AlertSource;
use App\Knowledge\Gaps\GapQuestions;
use App\Knowledge\Gaps\MeetingGaps;
use App\Knowledge\Gaps\TermGaps;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Looks for what the system does not know about the person's work and asks
 * a few of those questions. Meetings are looked at every time; terms once a
 * day, since that needs the model.
 */
class FindKnowledgeGaps implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 900;

    public function __construct(public User $user)
    {
        $this->onConnection('redis-long')->onQueue('context');
    }

    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    public function handle(MeetingGaps $meetings, TermGaps $terms, GapQuestions $questions): void
    {
        if (! $this->user->wantsAlertsFrom(AlertSource::Questions)) {
            return;
        }

        try {
            $meetings->find($this->user);
        } catch (Throwable $exception) {
            report($exception);
        }

        $termsKey = "knowledge-gaps:terms:{$this->user->id}:".now()->toDateString();

        if (Cache::add($termsKey, true, now()->addDay())) {
            try {
                $terms->find($this->user);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $questions->ask($this->user);
    }
}
