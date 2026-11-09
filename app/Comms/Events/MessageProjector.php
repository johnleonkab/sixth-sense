<?php

namespace App\Comms\Events;

use App\Comms\Ingestion\IdentityResolver;
use App\Comms\Messages\Direction;
use App\Comms\Messages\Party;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;

/**
 * Rebuilds message and person rows from the log. Identity audits are written
 * only while a live event is consumed, never while replaying.
 */
class MessageProjector
{
    public function __construct(private IdentityResolver $people) {}

    public function apply(CommsEvent $event, bool $audit): void
    {
        if ($event->type === EventType::PersonIdentified) {
            $this->people->apply($event);

            return;
        }

        if (! in_array($event->type, [
            EventType::MessageReceived,
            EventType::MessageSent,
            EventType::MessageEdited,
            EventType::MessageDeleted,
        ], true)) {
            return;
        }

        $normalized = $event->normalized ?? [];
        $author = $normalized['author'] ?? null;

        if (! is_array($author) || $event->user === null) {
            return;
        }

        $user = $event->user;
        $source = $event->source;
        $authorPerson = $this->people->resolve($user, $source, Party::fromArray($author), audit: $audit);
        $recipientIds = [];

        foreach ($normalized['recipients'] ?? [] as $recipient) {
            if (! is_array($recipient)) {
                continue;
            }

            $recipientIds[] = $this->people->resolve($user, $source, Party::fromArray($recipient), audit: $audit)->id;
        }

        $externalId = (string) ($normalized['external_id'] ?? $event->external_id);
        $existing = CommsMessage::query()
            ->whereBelongsTo($user)
            ->where('source', $source)
            ->where('external_id', $externalId)
            ->first();

        $text = (string) ($normalized['text'] ?? '');
        $links = array_values($normalized['links'] ?? []);
        $attachments = array_values($normalized['attachments'] ?? []);

        if ($event->type === EventType::MessageDeleted && $existing !== null) {
            $text = $text !== '' ? $text : $existing->text;
            $links = $links !== [] ? $links : ($existing->links ?? []);
            $attachments = $attachments !== [] ? $attachments : ($existing->attachments ?? []);
        }

        $direction = Direction::tryFrom((string) ($normalized['direction'] ?? '')) ?? Direction::Incoming;

        CommsMessage::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'source' => $source,
                'external_id' => $externalId,
            ],
            [
                'event_id' => $event->id,
                'channel_ref' => $normalized['channel'] ?? null,
                'thread_ref' => $normalized['thread'] ?? null,
                'author_person_id' => $authorPerson->id,
                'recipient_person_ids' => $recipientIds,
                'direction' => $direction->code(),
                'text' => $text,
                'links' => $links,
                'attachments' => $attachments,
                'flags' => $this->flags($normalized, $existing),
                'deleted_at' => $event->type === EventType::MessageDeleted ? $event->occurred_at : null,
                'occurred_at' => $normalized['at'] ?? $event->occurred_at,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array{sensitive: bool, urgent: bool, automated: bool, noise: bool, cc_only: bool}
     */
    private function flags(array $normalized, ?CommsMessage $existing): array
    {
        $flags = $existing->flags ?? [];

        return [
            'sensitive' => (bool) ($normalized['sensitive'] ?? $flags['sensitive'] ?? false),
            'urgent' => (bool) ($flags['urgent'] ?? false),
            'automated' => (bool) ($normalized['automated'] ?? $flags['automated'] ?? false),
            'noise' => (bool) ($flags['noise'] ?? false),
            'cc_only' => (bool) ($flags['cc_only'] ?? false),
        ];
    }
}
