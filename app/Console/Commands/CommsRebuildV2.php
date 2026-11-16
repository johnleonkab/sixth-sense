<?php

namespace App\Console\Commands;

use App\Comms\Backfill\RebuildV2;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('comms:rebuild-v2 {user : The user id}')]
#[Description('Replace intent projections with a v2 reading of the last 30 days')]
class CommsRebuildV2 extends Command
{
    public function handle(RebuildV2 $rebuild): int
    {
        $user = User::query()->find($this->argument('user'));

        if (! $user instanceof User) {
            $this->components->error('User not found.');

            return self::FAILURE;
        }

        $rebuild->run($user);
        $this->components->info('Intents rebuilt from the last 30 days.');

        return self::SUCCESS;
    }
}
