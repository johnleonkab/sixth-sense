<?php

namespace App\Console\Commands;

use App\Comms\Backfill\Passes;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('comms:ask-patterns {user? : The user id}')]
#[Description('Learn how each person asks, from labelled messages')]
class CommsAskPatterns extends Command
{
    public function handle(Passes $passes): int
    {
        $users = filled($this->argument('user'))
            ? User::query()->whereKey($this->argument('user'))->orderBy('id')->get()
            : User::query()->orderBy('id')->get();

        if (filled($this->argument('user')) && $users->isEmpty()) {
            $this->components->error('User not found.');

            return self::FAILURE;
        }

        foreach ($users as $user) {
            $counts = $passes->run($user, 'patterns');
            $this->components->info("{$user->id}: {$counts['facts']} patterns.");
        }

        return self::SUCCESS;
    }
}
