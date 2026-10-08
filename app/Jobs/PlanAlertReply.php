<?php

namespace App\Jobs;

use App\Alerts\AlertFollowUp;
use App\Models\InboxItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Asks the reply engine how to answer a new alert. Separate from raising the
 * alert, so the sound is not delayed by it.
 */
class PlanAlertReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    public int $timeout = 120;

    public function __construct(public InboxItem $item) {}

    public function handle(AlertFollowUp $followUp): void
    {
        $followUp->plan($this->item->fresh() ?? $this->item);
    }
}
