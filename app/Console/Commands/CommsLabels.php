<?php

namespace App\Console\Commands;

use App\Comms\Backfill\LabelMiner;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('comms:labels {user? : The user id}')]
#[Description('Label incoming messages the user acted on or ignored')]
class CommsLabels extends Command
{
    public function handle(LabelMiner $miner): int
    {
        $users = filled($this->argument('user'))
            ? User::query()->whereKey($this->argument('user'))->orderBy('id')->get()
            : User::query()->orderBy('id')->get();

        if (filled($this->argument('user')) && $users->isEmpty()) {
            $this->components->error('User not found.');

            return self::FAILURE;
        }

        foreach ($users as $user) {
            $counts = $miner->run($user);
            $this->components->info("{$user->id}: {$counts['acted']} acted, {$counts['ignored']} ignored.");
        }

        return self::SUCCESS;
    }
}
