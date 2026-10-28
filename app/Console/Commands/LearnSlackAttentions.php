<?php

namespace App\Console\Commands;

use App\Connections\Capability;
use App\Connections\Provider;
use App\Jobs\LearnSlackAttention;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('slack:learn-attention')]
#[Description('Queue a fresh Slack attention profile for everyone with Slack search')]
class LearnSlackAttentions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queued = 0;

        User::query()
            ->whereHas('connectedAccounts', fn ($accounts) => $accounts->where('provider', Provider::Slack))
            ->each(function (User $user) use (&$queued): void {
                if ($user->accountsWith(Capability::SlackSearch)->isEmpty()) {
                    return;
                }

                LearnSlackAttention::dispatch($user);
                $queued++;
            });

        $this->components->info("{$queued} perfiles en cola.");

        return self::SUCCESS;
    }
}
