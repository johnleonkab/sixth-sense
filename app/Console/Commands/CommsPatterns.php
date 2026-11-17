<?php

namespace App\Console\Commands;

use App\Comms\Playbooks\PatternCluster;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('comms:patterns {user? : The user id}')]
#[Description('Cluster repeated questions and support reports from the last 21 days')]
class CommsPatterns extends Command
{
    public function handle(PatternCluster $patterns): int
    {
        $users = filled($this->argument('user'))
            ? User::query()->whereKey($this->argument('user'))->orderBy('id')->get()
            : User::query()->orderBy('id')->get();

        if (filled($this->argument('user')) && $users->isEmpty()) {
            $this->components->error('User not found.');

            return self::FAILURE;
        }

        foreach ($users as $user) {
            $count = $patterns->run($user);
            $this->components->info("{$user->id}: {$count} patterns.");
        }

        return self::SUCCESS;
    }
}
