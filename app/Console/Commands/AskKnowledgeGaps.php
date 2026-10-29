<?php

namespace App\Console\Commands;

use App\Jobs\FindKnowledgeGaps;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('knowledge:ask')]
#[Description('Queue a look for what the system does not know about each connected person, and the questions it asks')]
class AskKnowledgeGaps extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queued = 0;

        User::query()
            ->whereHas('connectedAccounts')
            ->each(function (User $user) use (&$queued): void {
                FindKnowledgeGaps::dispatch($user);
                $queued++;
            });

        $this->components->info("{$queued} usuarios en cola.");

        return self::SUCCESS;
    }
}
