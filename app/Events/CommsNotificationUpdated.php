<?php

namespace App\Events;

use App\Comms\Cutover\HomeFeed;
use App\Models\Comms\CommsNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * An alert the person already has changed: a draft is ready, or a follow-up
 * joined the same intent. The open screen updates that row and does not
 * treat it as a new alert.
 */
class CommsNotificationUpdated implements ShouldBroadcastNow
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
        return 'inbox.item.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return app(HomeFeed::class)->card($this->notification);
    }
}
