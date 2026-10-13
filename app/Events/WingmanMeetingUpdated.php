<?php

namespace App\Events;

use App\Models\WingmanMeeting;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * A call Wingman follows changed (paused, resumed): their open sixth-sense updates its bubble.
 */
class WingmanMeetingUpdated implements ShouldBroadcastNow
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
        return 'wingman.meeting.updated';
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
