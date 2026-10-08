<?php

namespace App\Console\Commands;

use App\Alerts\AlertSource;
use App\Alerts\MeetingReminders;
use App\Models\AlertSetting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alerts:remind-meetings')]
#[Description('Ring the reminders of meetings that are about to start')]
class RemindMeetings extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MeetingReminders $reminders): int
    {
        $sent = 0;

        AlertSetting::query()
            ->where('source', AlertSource::Calendar)
            ->where('enabled', true)
            ->with('user')
            ->each(function (AlertSetting $setting) use ($reminders, &$sent): void {
                $sent += count($reminders->remindDue($setting->user));
            });

        $this->components->info("{$sent} avisos.");

        return self::SUCCESS;
    }
}
