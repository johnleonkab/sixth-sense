<?php

namespace App\Console\Commands;

use App\Comms\Nightly\ReconcileApplier;
use App\Comms\Nightly\Reconciler;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('comms:reconcile-nightly {user? : The user id} {--date= : The day to re-read, YYYY-MM-DD}')]
#[Description('Re-read each topic touched that day, store the changes, and apply the automatic ones when the reconciler is on')]
class CommsReconcileNightly extends Command
{
    public function handle(Reconciler $reconciler, ReconcileApplier $applier): int
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
            $applied = $applier->apply($user, $day);
            $this->components->info("{$user->id}: {$stored} changes, {$applied} applied.");
        }

        return self::SUCCESS;
    }
}
