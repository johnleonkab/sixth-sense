<?php

namespace App\Jobs;

use App\Alerts\AlertInbox;
use App\Alerts\AlertSource;
use App\Models\ConnectedAccount;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Checks one account for new messages that wait for the person (Gmail,
 * Slack). One at a time per account, so a slow check never overlaps with the
 * next minute's.
 */
class CheckAccountForAlerts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public int $timeout = 120;

    public function __construct(
        public ConnectedAccount $account,
        public AlertSource $source = AlertSource::Gmail,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->source->value}:{$this->account->id}";
    }

    public function handle(AlertInbox $inbox): void
    {
        $account = $this->account->fresh();

        // Things may have changed since it was queued.
        if ($account === null
            || ! $account->can($this->source->capability())
            || ! $account->user->wantsAlertsFrom($this->source)) {
            return;
        }

        $inbox->check($account, $this->source);
    }
}
