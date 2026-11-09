<?php

namespace App\Comms\Events;

use App\Comms\Jobs\Consuming;
use App\Comms\Jobs\ProcessCommsEvent;
use App\Comms\Messages\Direction;
use App\Comms\Messages\Message;
use App\Models\Comms\CommsEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer for the event log. A repeated source id returns the row
 * already stored, inserts nothing, and dispatches nothing.
 */
class EventLog
{
    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>|null  $normalized
     */
    public function append(
        User $user,
        EventType $type,
        Source $source,
        ?string $externalId,
        ?array $payload,
        ?array $normalized,
        ?CommsEvent $causation,
        DateTimeInterface $occurredAt,
        string $revision = '',
        bool $dispatch = true,
    ): CommsEvent {
        $externalId = filled($externalId) ? $externalId : null;

        if ($source === Source::Clock) {
            $externalId ??= CarbonImmutable::parse($occurredAt)->utc()->toIso8601String();
        }

        $inside = app(Consuming::class)->active;

        try {
            // A savepoint, so a duplicate key rolls back only this insert.
            // The surrounding test or request transaction stays usable.
            $event = DB::transaction(fn (): CommsEvent => CommsEvent::query()->create([
                'user_id' => $user->id,
                'type' => $type,
                'source' => $source,
                'external_id' => $externalId,
                'revision' => $revision,
                'payload' => $payload,
                'normalized' => $normalized,
                'causation_id' => $causation?->id,
                'occurred_at' => CarbonImmutable::parse($occurredAt),
                'processed_at' => $inside ? now() : null,
            ]));
        } catch (UniqueConstraintViolationException) {
            return CommsEvent::query()
                ->whereBelongsTo($user)
                ->where('type', $type)
                ->where('source', $source)
                ->where('external_id', $externalId)
                ->where('revision', $revision)
                ->firstOrFail();
        }

        if ($dispatch && $event->wasRecentlyCreated && ! $inside) {
            ProcessCommsEvent::dispatch($event->id, $event->user_id);
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function appendMessage(User $user, Message $message, ?array $payload = null, ?CommsEvent $causation = null): CommsEvent
    {
        return $this->append(
            user: $user,
            type: $message->direction === Direction::Outgoing ? EventType::MessageSent : EventType::MessageReceived,
            source: $message->source,
            externalId: $message->externalId,
            payload: $payload,
            normalized: $message->toArray(),
            causation: $causation,
            occurredAt: $message->at,
        );
    }
}
