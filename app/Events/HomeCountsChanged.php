<?php

namespace App\Events;

use App\Comms\Surface\HomeBoard;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * The four home counts changed. The open home replaces its counters.
 */
class HomeCountsChanged implements ShouldBroadcastNow
{
    use BroadcastsWhenItCan, InteractsWithSockets, SerializesModels;

    public function __construct(public User $user) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->user->id)];
    }

    public function broadcastAs(): string
    {
        return 'home.counts.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $board = HomeBoard::for($this->user);

        return [
            'user_id' => $this->user->id,
            'counters' => $board['counters'],
            'possible_asks' => $board['possible_asks'],
            'uncertain_links' => $board['uncertain_links'],
        ];
    }
}
