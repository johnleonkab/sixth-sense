<?php

namespace App\Console\Commands;

use App\Comms\Metrics\DailyMetrics;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('comms:metrics {user? : The user id} {--date= : The day to count, YYYY-MM-DD}')]
#[Description('Count one day of communications for the intent list')]
class CommsMetrics extends Command
{
    public function handle(DailyMetrics $metrics): int
    {
        $users = filled($this->argument('user'))
            ? User::query()->whereKey($this->argument('user'))->get()
            : User::query()->get();

        if (filled($this->argument('user')) && $users->isEmpty()) {
            $this->components->error('User not found.');

            return self::FAILURE;
        }

        $day = filled($this->option('date'))
            ? CarbonImmutable::parse((string) $this->option('date'))
            : CarbonImmutable::yesterday();

        foreach ($users as $user) {
            $row = $metrics->write($user, $day);
            $this->components->info("{$user->id} {$row->day->toDateString()}: {$row->messages_in} in, {$row->intents_opened} opened.");
        }

        return self::SUCCESS;
    }
}
