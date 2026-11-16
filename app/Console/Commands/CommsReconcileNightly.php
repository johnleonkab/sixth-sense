<?php

namespace App\Console\Commands;

use App\Comms\Nightly\Reconciler;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('comms:reconcile-nightly {user? : The user id} {--date= : The day to re-read, YYYY-MM-DD}')]
#[Description('Re-read each topic touched that day and store the proposed changes')]
class CommsReconcileNightly extends Command
{
    public function handle(Reconciler $reconciler): int
    {
        $users = filled($this->argument('user'))
            ? User::query()->whereKey($this->argument('user'))->orderBy('id')->get()
            : User::query()->orderBy('id')->get();

        if (filled($this->argument('user')) && $users->isEmpty()) {
            $this->components->error('User not found.');

            return self::FAILURE;
        }

        $date = $this->option('date');
        $day = is_string($date) && $date !== '' ? CarbonImmutable::parse($date) : null;

        foreach ($users as $user) {
            $stored = $reconciler->run($user, $day);
            $this->components->info("{$user->id}: {$stored} changes.");
        }

        return self::SUCCESS;
    }
}
