<?php

namespace App\Jobs;

use App\Alerts\Push\AlertPush;
use App\Models\InboxItem;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells the person's devices about an alert that is waiting for them.
 * The same message is not sent twice; a newer one in the conversation is.
 */
class SendAlertPush implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public int $uniqueFor = 60;

    public function __construct(public InboxItem $item) {}

    public function uniqueId(): string
    {
        return $this->item->pushKey();
    }

    public function handle(AlertPush $push): void
    {
        $item = $this->item->fresh();

        if ($item === null || ! $item->awaitsPush()) {
            return;
        }

        $key = $item->pushKey();

        if ($item->last_push_key === $key) {
            return;
        }

        if (! $push->deliver($item)) {
            return;
        }

        $item->update(['last_push_key' => $key]);
    }
}
