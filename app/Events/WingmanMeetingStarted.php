<?php

namespace App\Events;

use App\Models\WingmanMeeting;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * The person joined a call Wingman follows: their open sixth-sense shows Wingman's bubble.
 */
class WingmanMeetingStarted implements ShouldBroadcastNow
{
    use BroadcastsWhenItCan, InteractsWithSockets, SerializesModels;

    public function __construct(public WingmanMeeting $meeting) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->meeting->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'wingman.meeting.started';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->meeting->id,
            'title' => $this->meeting->title,
            'meeting_code' => $this->meeting->meeting_code,
            'status' => $this->meeting->status->value,
        ];
    }
}
