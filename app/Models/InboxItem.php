<?php

namespace App\Models;

use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\Triage;
use Database\Factories\InboxItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Something that deserved an alert, with why: a message waiting for the
 * person's answer (with how an AI proposes to answer it), or a reminder.
 */
#[Fillable(['source', 'external_id', 'thread_key', 'external_url', 'actor_name', 'title', 'body', 'why', 'relevance', 'status', 'triage', 'planning', 'resolution', 'payload', 'proposal', 'read_at', 'resolved_at', 'received_at'])]
class InboxItem extends Model
{
    /** @use HasFactory<InboxItemFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => AlertSource::class,
            'status' => AlertStatus::class,
            'triage' => Triage::class,
            'planning' => 'boolean',
            'payload' => 'array',
            'proposal' => 'array',
            'read_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The time the scheduler found for what this alert asks the person to do.
     *
     * @return HasOne<AgendaTask, $this>
     */
    public function agendaTask(): HasOne
    {
        return $this->hasOne(AgendaTask::class)->latestOfMany();
    }

    /**
     * Alerts still waiting for the person's answer.
     *
     * @param  Builder<InboxItem>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', AlertStatus::Pending);
    }

    /**
     * Close it: the person answered (here or in the service), or decided it needs nothing.
     */
    public function close(AlertStatus $status, string $resolution): void
    {
        $this->update(['status' => $status, 'resolution' => $resolution, 'resolved_at' => now()]);
    }

    /**
     * The message it is about: an email ref, or a Slack message ref.
     */
    public function messageRef(): string
    {
        return $this->external_id;
    }

    /**
     * Where it stands, as the screen shows it: judging, important, planning
     * (a proposal is being prepared), ready (proposal ready), routine,
     * resolved, dismissed or meeting.
     */
    public function state(): string
    {
        return match (true) {
            $this->source === AlertSource::Calendar => 'meeting',
            $this->triage === Triage::Judging => 'judging',
            $this->triage === Triage::Routine => 'routine',
            $this->status === AlertStatus::Resolved => 'resolved',
            $this->status === AlertStatus::Dismissed => 'dismissed',
            $this->planning => 'planning',
            filled($this->proposal) => 'ready',
            default => 'important',
        };
    }

    /**
     * Where it stands, in words.
     */
    public function stateLabel(): string
    {
        return match ($this->state()) {
            'meeting' => 'Reunión',
            'judging' => 'Analizando',
            'routine' => 'Sin importancia',
            'resolved' => 'Resuelta',
            'dismissed' => 'Descartada',
            'planning' => 'Pendiente, preparando propuesta',
            'ready' => 'Pendiente, con propuesta',
            default => 'Pendiente',
        };
    }

    /**
     * What the browser needs to ring and show the alert.
     *
     * @return array<string, mixed>
     */
    public function toAlert(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'actor_name' => $this->actor_name,
            'title' => $this->title,
            'why' => $this->why,
            'received_at' => $this->received_at->toIso8601String(),
            'external_id' => $this->external_id,
            'external_url' => $this->external_url,
            'account' => $this->payload['account'] ?? null,
            'starts_at' => $this->payload['starts_at'] ?? null,
            'meeting_url' => $this->payload['meeting_url'] ?? null,
            'event_ref' => $this->payload['event_ref'] ?? null,
            'status' => $this->status->value,
            'state' => $this->state(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution' => $this->resolution,
            'conversation' => $this->payload['conversation'] ?? null,
            'proposal' => $this->proposal ? ['summary' => $this->proposal['summary'] ?? null] : null,
            'task' => $this->agendaTask ? [
                'id' => $this->agendaTask->id,
                'status' => $this->agendaTask->status->value,
                'status_label' => $this->agendaTask->status->label(),
                'title' => $this->agendaTask->title,
                'starts_at' => $this->agendaTask->starts_at?->toIso8601String(),
            ] : null,
        ];
    }
}
