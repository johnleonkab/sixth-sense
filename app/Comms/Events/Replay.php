<?php

namespace App\Comms\Events;

use App\Comms\Intents\IntentReplay;
use App\Models\Comms\CommsEvent;
use App\Models\User;
use DateTimeInterface;

/**
 * Walks the log in id order and rebuilds projections. It never calls a model
 * or a connector. Source rows are applied again; stored decisions stay in the log.
 */
class Replay
{
    public function __construct(
        private Projections $projections,
        private MessageProjector $projector,
        private IntentReplay $intents,
    ) {}

    public function run(User $user, ?DateTimeInterface $from = null, ?DateTimeInterface $to = null, bool $rejudge = false): int
    {
        $this->projections->clear($user);

        $events = CommsEvent::query()
            ->whereBelongsTo($user)
            ->when($from !== null, fn ($query) => $query->where('occurred_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('occurred_at', '<=', $to))
            ->orderBy('id')
            ->get();

        foreach ($events as $event) {
            $this->projector->apply($event, audit: false);
            $this->intents->apply($event);
        }

        return $events->count();
    }
}
