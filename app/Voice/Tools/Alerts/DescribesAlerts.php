<?php

namespace App\Voice\Tools\Alerts;

use App\Alerts\AlertSource;
use App\Models\InboxItem;
use Illuminate\Support\Str;

/**
 * How alerts are told to the assistant: what, who, why, where they stand,
 * and the ids its other tools need.
 */
trait DescribesAlerts
{
    /**
     * @return array<string, mixed>
     */
    private function forAssistant(InboxItem $item, string $timezone): array
    {
        return array_filter([
            'alert_id' => $item->id,
            'source' => $item->source->label(),
            'from' => $item->actor_name,
            'subject' => $item->source === AlertSource::Slack ? null : $item->title,
            'where' => $item->source === AlertSource::Slack ? $item->title : null,
            'why_it_matters' => $item->why,
            'received' => $item->received_at->setTimezone($timezone)->locale('es')->diffForHumans(),
            'status' => $item->status->label(),
            'proposal' => $item->proposal['summary'] ?? null,
            'email_id' => $item->source === AlertSource::Gmail ? $item->external_id : null,
            'message_id' => $item->source === AlertSource::Slack ? $item->external_id : null,
            'conversation_id' => $item->source === AlertSource::Slack ? Str::beforeLast($item->external_id, ':') : null,
            'event_id' => $item->payload['event_ref'] ?? null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * What the alert list canvas shows of it.
     *
     * @return array<string, mixed>
     */
    private function forCanvas(InboxItem $item, int $number): array
    {
        return [...$item->toAlert(), 'number' => $number];
    }
}
