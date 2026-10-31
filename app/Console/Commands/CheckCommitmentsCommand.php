<?php

namespace App\Console\Commands;

use App\Alerts\AlertSource;
use App\Jobs\CheckCommitments;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alerts:check-commitments')]
#[Description('Turn what the person promised into proposed calendar time, and follow up tasks due tomorrow')]
class CheckCommitmentsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queued = 0;

        User::query()
            ->whereHas('alertSettings', fn ($settings) => $settings->where('source', AlertSource::Agenda)->where('enabled', true))
            ->each(function (User $user) use (&$queued): void {
                CheckCommitments::dispatch($user);
                $queued++;
            });

        $this->components->info("{$queued} usuarios en cola.");

        return self::SUCCESS;
    }
}
