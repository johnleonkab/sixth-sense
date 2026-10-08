<?php

namespace App\Console\Commands;

use App\Alerts\AlertSource;
use App\Jobs\PlanMeetingReminders;
use App\Models\AlertSetting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alerts:plan-meetings')]
#[Description('Plan reminders for the upcoming meetings of users who want them')]
class PlanMeetings extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queued = 0;

        AlertSetting::query()
            ->where('source', AlertSource::Calendar)
            ->where('enabled', true)
            ->with('user')
            ->each(function (AlertSetting $setting) use (&$queued): void {
                PlanMeetingReminders::dispatch($setting->user);
                $queued++;
            });

        $this->components->info("{$queued} usuarios en cola.");

        return self::SUCCESS;
    }
}
