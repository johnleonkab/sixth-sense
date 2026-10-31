<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Each lock expires shortly after the next run is due, so a run killed
 * mid-way (a deploy, a crash) does not block the command for the default day.
 */
Schedule::command('alerts:watch')->everyMinute()->withoutOverlapping(5);
Schedule::command('alerts:check-replies')->everyTwoMinutes()->withoutOverlapping(10);
Schedule::command('alerts:check-commitments')->everyTenMinutes()->withoutOverlapping(20);

foreach (config('alerts.digest_at') as $time) {
    Schedule::command('alerts:digest')->weekdays()->dailyAt($time)->timezone(config('agenda.timezone'));
}

Schedule::command('alerts:plan-meetings')->everyTenMinutes()->withoutOverlapping(30);
Schedule::command('alerts:remind-meetings')->everyMinute()->withoutOverlapping(5);
Schedule::command('chats:warm')->hourly()->withoutOverlapping(60);
Schedule::command('context:build')->dailyAt(config('work_context.run_at'))->withoutOverlapping(120);
Schedule::command('alerts:unstick')->everyFiveMinutes()->withoutOverlapping(15);
Schedule::command('wingman:sync')->everyTenMinutes()->withoutOverlapping(30);
Schedule::command('slack:learn-attention')->weeklyOn(1, '6:00')->withoutOverlapping(120);
Schedule::command('slack:promote-unanswered')->everyThirtyMinutes()->withoutOverlapping(60);
Schedule::command('knowledge:sync')->everyFifteenMinutes()->withoutOverlapping(30);
Schedule::command('knowledge:sync --full')->dailyAt('03:30')->withoutOverlapping(120);
Schedule::command('knowledge:ask')->hourlyAt(20)->withoutOverlapping(30);
