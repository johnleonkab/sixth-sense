<?php

namespace App\Events;

use App\Comms\Cutover\HomeFeed;
use App\Models\Comms\CommsNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * The communications pipeline has something to say: the person's open
 * sixth-sense shows it as an alert, as if it were a new inbox item.
 * Sent right away, since it is already raised from a queued job.
 */
class CommsNotificationDelivered implements ShouldBroadcastNow
{
    use BroadcastsWhenItCan, InteractsWithSockets, SerializesModels;

    public function __construct(public CommsNotification $notification) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->notification->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'inbox.item.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return app(HomeFeed::class)->card($this->notification);
    }
}
