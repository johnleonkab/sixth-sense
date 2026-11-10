<?php

namespace App\Jobs;

use App\Alerts\Push\AlertPush;
use App\Comms\Cutover\HomeFeed;
use App\Models\Comms\CommsNotification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells the person's devices about a communications notification that
 * cannot wait, with the same card the open screen shows.
 */
class SendCommsPush implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public int $uniqueFor = 60;

    public function __construct(public CommsNotification $notification) {}

    public function uniqueId(): string
    {
        return 'comms:'.$this->notification->id;
    }

    public function handle(AlertPush $push, HomeFeed $feed): void
    {
        $notification = $this->notification->fresh();

        if ($notification === null || $notification->dismissed_at !== null || $notification->urgency !== 'now') {
            return;
        }

        $card = $feed->card($notification);

        if ($card['status'] !== 'pending') {
            return;
        }

        $push->deliverCard($notification->user, $card);
    }
}
