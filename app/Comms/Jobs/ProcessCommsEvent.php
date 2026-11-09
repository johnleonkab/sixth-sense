<?php

namespace App\Comms\Jobs;

use App\Comms\Events\MessageProjector;
use App\Comms\Intents\Pipeline;
use App\Models\Comms\CommsEvent;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * The only consumer. One user is handled at a time, and a later event waits
 * until every earlier event for that user has been processed.
 */
class ProcessCommsEvent implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(public int $eventId, public int $userId)
    {
        $this->onQueue('comms-decide');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(MessageProjector $projector, Pipeline $pipeline, Consuming $consuming): void
    {
        $lock = Cache::lock('comms-decide:'.$this->userId, 300);

        if (! $lock->get()) {
            $this->release(5);

            return;
        }

        try {
            $waiting = CommsEvent::query()
                ->where('user_id', $this->userId)
                ->whereNull('processed_at')
                ->where('id', '<', $this->eventId)
                ->exists();

            if ($waiting) {
                $this->release(2);

                return;
            }

            $event = CommsEvent::query()->find($this->eventId);

            if ($event === null || $event->processed_at !== null) {
                return;
            }

            $consuming->active = true;

            try {
                $projector->apply($event, audit: true);
                $pipeline->advance($event);

                if ($event->processed_at === null) {
                    $event->processed_at = now();
                    $event->save();
                }
            } finally {
                $consuming->active = false;
            }
        } finally {
            $lock->release();
        }
    }
}
