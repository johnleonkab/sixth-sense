<?php

namespace App\Comms\Ingestion;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Messages\Direction;
use App\Comms\Messages\Message;
use App\Models\Comms\CommsEvent;
use App\Models\User;

/**
 * Turns an in-scope Slack message into one log row. Edits and deletes keep
 * the original message id and carry a new revision.
 */
class SlackNormalizer
{
    public function __construct(private EventLog $log, private Scope $scope) {}

    public function ingest(User $user, SlackInbound $inbound, bool $backfill = false): ?CommsEvent
    {
        if (! $this->scope->allowsSlack($user, $inbound)) {
            return null;
        }

        $direction = $inbound->mine ? Direction::Outgoing : Direction::Incoming;
        $message = new Message(
            externalId: $inbound->externalId(),
            source: Source::Slack,
            channel: $inbound->channelId,
            thread: $inbound->threadRef(),
            author: $inbound->author,
            recipients: $inbound->recipients,
            direction: $direction,
            at: $inbound->at,
            text: $inbound->text,
            links: Message::linksIn($inbound->text),
            attachments: $inbound->attachments,
            automated: $inbound->author->bot || $inbound->subtype === 'bot_message',
        );

        $normalized = $message->toArray();
        $normalized['backfill'] = $backfill;

        return $this->log->append(
            user: $user,
            type: $this->type($inbound),
            source: Source::Slack,
            externalId: $inbound->externalId(),
            payload: null,
            normalized: $normalized,
            causation: null,
            occurredAt: $inbound->at,
            revision: $inbound->subtype === 'message_changed' ? ($inbound->revision ?? hash('sha256', $inbound->text)) : '',
        );
    }

    private function type(SlackInbound $inbound): EventType
    {
        return match ($inbound->subtype) {
            'message_changed' => EventType::MessageEdited,
            'message_deleted' => EventType::MessageDeleted,
            default => $inbound->mine ? EventType::MessageSent : EventType::MessageReceived,
        };
    }
}
