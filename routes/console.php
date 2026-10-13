<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('alerts:watch')->everyMinute()->withoutOverlapping();
Schedule::command('alerts:check-replies')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('alerts:plan-meetings')->everyTenMinutes()->withoutOverlapping();
Schedule::command('alerts:remind-meetings')->everyMinute()->withoutOverlapping();
Schedule::command('chats:warm')->hourly()->withoutOverlapping();
Schedule::command('context:build')->dailyAt(config('work_context.run_at'))->withoutOverlapping();
Schedule::command('alerts:unstick')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('wingman:sync')->everyTenMinutes()->withoutOverlapping();
