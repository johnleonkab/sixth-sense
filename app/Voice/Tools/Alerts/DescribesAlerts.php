<?php

namespace App\Voice\Tools\Alerts;

use App\Alerts\AlertSource;
use App\Alerts\Replies\AlertBrief;
use App\Models\InboxItem;
use App\Voice\Tools\Canvas;
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
            'status' => $item->stateLabel(),
            'proposal' => $item->proposal['summary'] ?? null,
            'scheduled' => $item->agendaTask ? "{$item->agendaTask->status->label()}: {$item->agendaTask->title}".($item->agendaTask->starts_at ? ', '.$item->agendaTask->starts_at->setTimezone($timezone)->locale('es')->isoFormat('dddd D, HH:mm') : '') : null,
            'task_id' => $item->agendaTask?->id,
            'email_id' => $item->source === AlertSource::Gmail ? $item->external_id : null,
            'message_id' => $item->source === AlertSource::Slack ? $item->external_id : null,
            'conversation_id' => $item->source === AlertSource::Slack ? Str::beforeLast($item->external_id, ':') : null,
            'event_id' => $item->payload['event_ref'] ?? null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * One alert under review: the message, where the alert stands and the proposal.
     */
    private function reviewCanvas(InboxItem $item, ?AlertBrief $brief): Canvas
    {
        return new Canvas('alerts.review', [
            'alert' => $item->toAlert(),
            'source_label' => $item->source->label(),
            'from' => $brief?->from ?? $item->actor_name,
            'subject' => $brief?->subject,
            'where' => $brief?->where ?? $item->title,
            'text' => $brief?->text ?? $item->body,
            'conversation' => $brief?->conversation ?? [],
            'participants' => $brief?->participants ?? [],
            'proposal' => $item->proposal,
            'task' => $item->agendaTask?->toCard(config('agenda.timezone')),
            'is_email' => $item->source === AlertSource::Gmail,
        ], layout: 'wide');
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
