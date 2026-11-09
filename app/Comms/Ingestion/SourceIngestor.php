<?php

namespace App\Comms\Ingestion;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Messages\Direction;
use App\Comms\Messages\Message;
use App\Comms\Messages\Party;
use App\Models\Comms\CommsEvent;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Jira, Notion and GitHub enter here. A comment is a message. A status
 * change, edit or merge is a source.changed event. Callers pass items a
 * read-only client already fetched. This class does not call those APIs.
 */
class SourceIngestor
{
    public function __construct(private EventLog $log, private IdentityResolver $people) {}

    public function comment(User $user, Source $source, string $externalId, string $channel, Party $author, string $text, CarbonImmutable $at): ?CommsEvent
    {
        $this->people->ensureSelf($user);
        $message = new Message(
            externalId: $externalId,
            source: $source,
            channel: $channel,
            thread: $channel,
            author: $author,
            recipients: [],
            direction: Direction::Incoming,
            at: $at,
            text: $text,
        );

        return $this->log->append(
            user: $user,
            type: EventType::MessageReceived,
            source: $source,
            externalId: $externalId,
            payload: null,
            normalized: $message->toArray(),
            causation: null,
            occurredAt: $at,
        );
    }

    public function changed(User $user, Source $source, string $externalId, string $kind, string $ref, string $text, CarbonImmutable $at): CommsEvent
    {
        return $this->log->append(
            user: $user,
            type: EventType::SourceChanged,
            source: $source,
            externalId: $externalId,
            payload: null,
            normalized: [
                'kind' => $kind,
                'ref' => $ref,
                'text' => $text,
            ],
            causation: null,
            occurredAt: $at,
        );
    }
}
