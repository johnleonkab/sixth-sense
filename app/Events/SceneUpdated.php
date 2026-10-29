<?php

namespace App\Events;

use App\Models\Scene;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * One card of a scene changed. The open screen patches that card.
 * Sent at once because the job that raised it is already off the request.
 */
class SceneUpdated implements ShouldBroadcastNow
{
    use BroadcastsWhenItCan, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<array<string, mixed>>  $cards
     * @param  ?array{view: string, props: array<string, mixed>, layout: string}  $beside
     */
    public function __construct(
        public int $userId,
        public int $sceneId,
        public string $status,
        public array $cards,
        public ?string $offer,
        public ?array $beside = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'scene.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'scene_id' => $this->sceneId,
            'status' => $this->status,
            'cards' => $this->cards,
            'offer' => $this->offer,
            'beside' => $this->beside,
        ];
    }

    /**
     * A copy of the cards as they are now, so a later fill cannot rewrite this event.
     */
    public static function forScene(Scene $scene, ?string $offer): void
    {
        $cards = $scene->canvas['cards'] ?? [];
        $beside = $scene->canvas['beside'] ?? null;

        self::dispatch(
            $scene->user_id,
            $scene->id,
            $scene->status,
            is_array($cards) ? array_values($cards) : [],
            $offer,
            is_array($beside) ? $beside : null,
        );
    }
}
