<?php

namespace App\Jobs;

use App\Knowledge\KnowledgeSync;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Copies what changed in the person's archive, Jira and Notion into the
 * knowledge base. It runs on the single long-jobs worker, one person at a
 * time, so a large first copy never competes with the alerts.
 */
class SyncKnowledge implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    public bool $failOnTimeout = true;

    public function __construct(public User $user, public bool $full = false)
    {
        $this->onConnection('redis-long')->onQueue('context');
    }

    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    public function handle(KnowledgeSync $sync): void
    {
        $sync->sync($this->user, $this->full);
    }
}
