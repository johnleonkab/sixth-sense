<?php

namespace App\Console\Commands;

use App\Jobs\SyncKnowledge;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('knowledge:sync {--full : List everything again and drop what disappeared}')]
#[Description('Queue a copy of what changed in the archive, Jira and Notion of every connected user')]
class SyncKnowledgeBases extends Command
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
                SyncKnowledge::dispatch($user, (bool) $this->option('full'));
                $queued++;
            });

        $this->components->info("{$queued} usuarios en cola.");

        return self::SUCCESS;
    }
}
