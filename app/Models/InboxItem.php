<?php

namespace App\Models;

use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\InboxCandidate;
use App\Alerts\Triage;
use Database\Factories\InboxItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Something that deserved an alert, with why: a message waiting for the
 * person's answer (with how an AI proposes to answer it), or a reminder.
 */
#[Fillable(['source', 'external_id', 'thread_key', 'external_url', 'actor_name', 'title', 'body', 'why', 'relevance', 'status', 'triage', 'planning', 'resolution', 'payload', 'proposal', 'read_at', 'resolved_at', 'received_at', 'last_push_key'])]
class InboxItem extends Model
{
    /** @use HasFactory<InboxItemFactory> */
    use HasFactory;

    /** Messages of a conversation an alert keeps (the newest). */
    public const MAX_MESSAGES = 20;

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
     * What happened to what was proposed for it.
     *
     * @return HasMany<ProposalOutcome, $this>
     */
    public function outcomes(): HasMany
    {
        return $this->hasMany(ProposalOutcome::class);
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
     * The scene this alert opened, when the alert matched a scene kind.
     *
     * @return HasOne<Scene, $this>
     */
    public function scene(): HasOne
    {
        return $this->hasOne(Scene::class);
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
     * The messages of the conversation since the person last dealt with it, oldest first.
     *
     * @return list<array{id: string, from: ?string, text: string, at: string}>
     */
    public function messages(): array
    {
        return $this->payload['messages'] ?? [[
            'id' => $this->external_id,
            'from' => $this->actor_name,
            'text' => (string) $this->body,
            'at' => $this->received_at->toIso8601String(),
        ]];
    }

    /**
     * Conversations this intent already covers, across channels.
     *
     * @return list<string>
     */
    public function threads(): array
    {
        return array_values(array_unique(array_filter([
            ...($this->payload['threads'] ?? []),
            $this->thread_key,
        ])));
    }

    /**
     * One message of a conversation, as an alert keeps it.
     *
     * @return array{id: string, from: ?string, text: string, at: string, source: string}
     */
    public static function messageOf(InboxCandidate $candidate): array
    {
        return [
            'id' => $candidate->externalId,
            'from' => $candidate->from?->label(),
            'text' => mb_substr($candidate->excerpt, 0, 1000),
            'at' => $candidate->receivedAt->toIso8601String(),
            'source' => $candidate->source->value,
        ];
    }

    /**
     * The message it is about (the latest of its conversation): an email ref, or a Slack message ref.
     */
    public function messageRef(): string
    {
        return $this->external_id;
    }

    /**
     * Waiting for the person: the states that already ring inside the app.
     */
    public function awaitsPush(): bool
    {
        return in_array($this->state(), ['important', 'planning', 'ready'], true) && ! $this->quiet();
    }

    /**
     * The nearest date by which something is due: a task's deadline, or today
     * for a call wanted now. Null when nothing has a date.
     */
    public function deadline(): ?string
    {
        $dates = collect($this->proposal['actions'] ?? [])
            ->map(fn (array $action): ?string => $action['data']['deadline'] ?? (in_array($action['data']['urgency'] ?? null, ['now', 'today'], true) ? now(config('agenda.timezone'))->toDateString() : null))
            ->push($this->agendaTask?->deadline?->toDateString())
            ->filter();

        return $dates->isEmpty() ? null : $dates->min();
    }

    /**
     * Someone is waiting on the person: they asked for a call or a question.
     */
    public function blocking(): bool
    {
        return collect($this->proposal['actions'] ?? [])->contains(fn (array $action): bool => in_array($action['class'] ?? null, ['call', 'question'], true));
    }

    /**
     * Low urgency: it waits for the person but need not interrupt them. It
     * does not ring, and goes in the twice-daily digest instead.
     */
    public function quiet(): bool
    {
        $deadline = $this->deadline();

        return $this->status === AlertStatus::Pending
            && ! in_array($this->source, [AlertSource::Calendar, AlertSource::Agenda, AlertSource::Questions], true)
            && $this->relevance < (int) config('alerts.quiet_below')
            && ! $this->blocking()
            && ($deadline === null || $deadline > now(config('agenda.timezone'))->addDays(2)->toDateString());
    }

    /**
     * One notification per message. A proposal becoming ready does not change it.
     */
    public function pushKey(): string
    {
        return $this->id.':'.count($this->messages()).':'.$this->external_id;
    }

    /**
     * Where it stands, as the screen shows it: judging, important, planning
     * (a proposal is being prepared), ready (proposal ready), routine,
     * resolved, dismissed, meeting or question (something the system asks).
     */
    public function state(): string
    {
        return match (true) {
            // Closed by the person (or answered) says it all, whatever the judge thought.
            $this->status === AlertStatus::Resolved => 'resolved',
            $this->status === AlertStatus::Dismissed => 'dismissed',
            $this->source === AlertSource::Calendar => 'meeting',
            $this->source === AlertSource::Questions => 'question',
            $this->triage === Triage::Judging => 'judging',
            $this->triage === Triage::Routine => 'routine',
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
            'question' => 'Pregunta',
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
            'deadline' => $this->deadline(),
            'blocking' => $this->blocking(),
            'quiet' => $this->quiet(),
            'conversation' => $this->payload['conversation'] ?? null,
            'messages' => $this->source === AlertSource::Calendar ? [] : $this->messages(),
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
