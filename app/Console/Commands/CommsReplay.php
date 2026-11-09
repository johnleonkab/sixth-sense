<?php

namespace App\Console\Commands;

use App\Comms\Events\Replay;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('comms:replay {user : The user id} {--from=} {--to=} {--rejudge : Walk the log without calling a model or a connector}')]
#[Description('Rebuild communications projections from the event log')]
class CommsReplay extends Command
{
    public function handle(Replay $replay): int
    {
        $user = User::query()->find($this->argument('user'));

        if (! $user instanceof User) {
            $this->components->error('User not found.');

            return self::FAILURE;
        }

        $from = filled($this->option('from')) ? CarbonImmutable::parse((string) $this->option('from')) : null;
        $to = filled($this->option('to')) ? CarbonImmutable::parse((string) $this->option('to')) : null;
        $count = $replay->run($user, $from, $to, (bool) $this->option('rejudge'));

        $this->components->info("Replayed {$count} events.");

        return self::SUCCESS;
    }
}
