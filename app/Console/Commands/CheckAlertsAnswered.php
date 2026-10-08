<?php

namespace App\Console\Commands;

use App\Alerts\AlertStatus;
use App\Jobs\CheckAlertReplies;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alerts:check-replies')]
#[Description('Resolve pending alerts the user already answered, here or in the service itself')]
class CheckAlertsAnswered extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queued = 0;

        User::query()
            ->whereHas('inboxItems', fn ($items) => $items->where('status', AlertStatus::Pending))
            ->each(function (User $user) use (&$queued): void {
                CheckAlertReplies::dispatch($user);
                $queued++;
            });

        $this->components->info("{$queued} usuarios en cola.");

        return self::SUCCESS;
    }
}
