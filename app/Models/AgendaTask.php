<?php

namespace App\Models;

use App\Scheduling\AgendaTaskStatus;
use App\Scheduling\Urgency;
use Database\Factories\AgendaTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the person has to do, that the scheduler found time for: in
 * their calendar (their own tasks), proposed (meetings with others), or
 * without a slot (they are alerted).
 */
#[Fillable(['inbox_item_id', 'origin', 'title', 'context', 'status', 'kind', 'urgency', 'urgency_reason', 'duration_minutes', 'deadline', 'attendees', 'starts_at', 'ends_at', 'event_ref', 'event_url', 'pending_action_id', 'error'])]
class AgendaTask extends Model
{
    /** @use HasFactory<AgendaTaskFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'assessing',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AgendaTaskStatus::class,
            'urgency' => Urgency::class,
            'attendees' => 'array',
            'deadline' => 'immutable_date',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
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
     * @return BelongsTo<InboxItem, $this>
     */
    public function inboxItem(): BelongsTo
    {
        return $this->belongsTo(InboxItem::class);
    }

    /**
     * @return BelongsTo<PendingAction, $this>
     */
    public function pendingAction(): BelongsTo
    {
        return $this->belongsTo(PendingAction::class);
    }

    /**
     * What the screen shows of it.
     *
     * @return array<string, mixed>
     */
    public function toCard(string $timezone): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'kind' => $this->kind,
            'urgency' => $this->urgency?->value,
            'urgency_label' => $this->urgency?->label(),
            'urgency_reason' => $this->urgency_reason,
            'duration_minutes' => $this->duration_minutes,
            'deadline' => $this->deadline?->toDateString(),
            'attendees' => $this->attendees ?? [],
            'starts_at' => $this->starts_at?->setTimezone($timezone)->toIso8601String(),
            'ends_at' => $this->ends_at?->setTimezone($timezone)->toIso8601String(),
            'event_url' => $this->event_url,
            'pending_action_id' => $this->pending_action_id,
            'error' => $this->error,
        ];
    }
}
