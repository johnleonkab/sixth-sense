<?php

namespace App\Console\Commands;

use App\Connections\Provider;
use App\Jobs\WarmChats;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('chats:warm')]
#[Description('Refresh what is known about the chat workspaces of users with Slack connected')]
class WarmChatWorkspaces extends Command
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
                WarmChats::dispatch($user);
                $queued++;
            });

        $this->components->info("{$queued} usuarios en cola.");

        return self::SUCCESS;
    }
}
