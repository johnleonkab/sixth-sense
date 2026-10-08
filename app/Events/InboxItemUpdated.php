<?php

namespace App\Events;

use App\Models\InboxItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An alert changed: its proposal is ready, a new message joined it, or it was
 * resolved. The person's open sixth-sense updates its list, without ringing.
 */
class InboxItemUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public InboxItem $item) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->item->user_id)];
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
        return $this->item->toAlert();
    }
}
