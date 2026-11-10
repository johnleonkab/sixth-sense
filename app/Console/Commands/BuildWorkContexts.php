<?php

namespace App\Console\Commands;

use App\Comms\Context\ContextHarvest;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('context:build')]
#[Description('Queue the nightly work context of every user who switched it on')]
class BuildWorkContexts extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queued = 0;

        User::query()
            ->whereHas('workContext', fn ($context) => $context->where('enabled', true))
            ->each(function (User $user) use (&$queued): void {
                ContextHarvest::dispatch($user);
                $queued++;
            });

        $this->components->info("{$queued} usuarios en cola.");

        return self::SUCCESS;
    }
}
