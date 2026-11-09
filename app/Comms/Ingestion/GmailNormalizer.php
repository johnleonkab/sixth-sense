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
 * Turns an in-scope Gmail message into one log row. The thread id is the
 * Gmail thread, so later messages in that thread share a thread ref.
 */
class GmailNormalizer
{
    public function __construct(private EventLog $log, private Scope $scope) {}

    public function ingest(User $user, GmailInbound $inbound, bool $backfill = false): ?CommsEvent
    {
        if (! $this->scope->allowsGmail($inbound)) {
            return null;
        }

        $labels = array_map(strtoupper(...), $inbound->labels);
        $outgoing = $inbound->mine || in_array('SENT', $labels, true);
        $text = $inbound->text();
        $message = new Message(
            externalId: $inbound->messageId,
            source: Source::Gmail,
            channel: $outgoing ? 'sent' : 'inbox',
            thread: $inbound->threadId,
            author: $inbound->from,
            recipients: $inbound->to,
            direction: $outgoing ? Direction::Outgoing : Direction::Incoming,
            at: $inbound->at,
            text: $text,
            links: Message::linksIn($text),
            attachments: $inbound->attachments,
        );

        $normalized = $message->toArray();
        $normalized['backfill'] = $backfill;

        return $this->log->append(
            user: $user,
            type: $outgoing ? EventType::MessageSent : EventType::MessageReceived,
            source: Source::Gmail,
            externalId: $inbound->messageId,
            payload: null,
            normalized: $normalized,
            causation: null,
            occurredAt: $inbound->at,
        );
    }
}
