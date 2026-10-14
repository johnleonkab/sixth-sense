<?php

namespace App\Models;

use Database\Factories\WingmanInsightFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something Wingman brought in during a call: a free slot for a follow-up,
 * how an issue stands, the page being talked about, a message prepared.
 */
#[Fillable(['kind', 'title', 'body', 'why', 'quote', 'links', 'action', 'pending_action_id', 'importance', 'dedupe_key', 'feedback', 'dismissed_at'])]
class WingmanInsight extends Model
{
    /** @use HasFactory<WingmanInsightFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'links' => 'array',
            'action' => 'array',
            'dismissed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<WingmanMeeting, $this>
     */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(WingmanMeeting::class, 'wingman_meeting_id');
    }

    /**
     * @return BelongsTo<PendingAction, $this>
     */
    public function pendingAction(): BelongsTo
    {
        return $this->belongsTo(PendingAction::class);
    }

    /**
     * What the bubble and the meeting page show of it.
     *
     * @return array<string, mixed>
     */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'why' => $this->why,
            'links' => $this->links ?? [],
            'action' => $this->action ? ['label' => $this->action['label'], 'preview' => $this->action['preview'] ?? null] : null,
            'importance' => $this->importance,
            'feedback' => $this->feedback,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
