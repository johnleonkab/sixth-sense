<?php

namespace App\Jobs;

use App\Alerts\AlertFollowUp;
use App\Models\InboxItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Asks the reply engine how to answer a new alert. Separate from raising the
 * alert, so the sound is not delayed by it.
 */
class PlanAlertReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * The AI may be over its rate limit: wait before trying again.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public int $timeout = 120;

    public function __construct(public InboxItem $item) {}

    public function handle(AlertFollowUp $followUp): void
    {
        $followUp->plan($this->item->fresh() ?? $this->item);
    }

    /**
     * Out of tries: the alert stays, without a proposal; it can be asked for again when reviewed.
     */
    public function failed(?Throwable $exception): void
    {
        if ($item = $this->item->fresh()) {
            app(AlertFollowUp::class)->stopPlanning($item);
        }
    }
}
