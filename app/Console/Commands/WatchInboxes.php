<?php

namespace App\Console\Commands;

use App\Alerts\AlertSource;
use App\Jobs\CheckAccountForAlerts;
use App\Models\AlertSetting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alerts:watch')]
#[Description('Queue a check for new messages waiting for an answer in every watched Gmail, Slack and Jira account')]
class WatchInboxes extends Command
{
    private const SOURCES = [AlertSource::Gmail, AlertSource::Slack, AlertSource::Jira];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queued = 0;

        AlertSetting::query()
            ->whereIn('source', self::SOURCES)
            ->where('enabled', true)
            ->with('user')
            ->each(function (AlertSetting $setting) use (&$queued): void {
                foreach ($setting->user->accountsWith($setting->source->capability()) as $account) {
                    CheckAccountForAlerts::dispatch($account, $setting->source);
                    $queued++;
                }
            });

        $this->components->info("{$queued} cuentas en cola.");

        return self::SUCCESS;
    }
}
