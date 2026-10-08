<?php

namespace App\Models;

use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use Database\Factories\InboxItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something that deserved an alert, with why: a message waiting for the
 * person's answer (with how an AI proposes to answer it), or a reminder.
 */
#[Fillable(['source', 'external_id', 'thread_key', 'external_url', 'actor_name', 'title', 'body', 'why', 'relevance', 'status', 'resolution', 'payload', 'proposal', 'read_at', 'resolved_at', 'received_at'])]
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
            'resolution' => $this->resolution,
            'conversation' => $this->payload['conversation'] ?? null,
            'proposal' => $this->proposal ? ['summary' => $this->proposal['summary'] ?? null] : null,
        ];
    }
}
