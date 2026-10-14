<?php

namespace App\Console\Commands;

use App\Connections\Capability;
use App\Jobs\SyncWingmanMeetings;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('wingman:sync')]
#[Description("Find today's Meet calls of the people who turned Wingman on, and prepare the next ones")]
class SyncWingman extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queued = 0;

        User::query()
            ->where('wingman_enabled', true)
            // In demo mode the sample calendar stands in for a connected one.
            ->unless(config('connectors.demo'), fn ($users) => $users->whereHas('connectedAccounts', fn ($accounts) => $accounts->withCapability(Capability::CalendarRead)))
            ->each(function (User $user) use (&$queued): void {
                SyncWingmanMeetings::dispatch($user);
                $queued++;
            });

        $this->components->info("{$queued} usuarios en cola.");

        return self::SUCCESS;
    }
}
