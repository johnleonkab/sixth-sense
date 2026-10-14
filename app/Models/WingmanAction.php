<?php

namespace App\Models;

use App\Actions\ActionStatus;
use Database\Factories\WingmanActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something to do after a call, ready: call the follow-up, send the message,
 * create the issue, book time for a task. Nothing happens until the person
 * reviews it and says "sí".
 */
#[Fillable(['wingman_insight_id', 'kind', 'title', 'detail', 'type', 'label', 'payload', 'pending_action_id', 'dismissed_at', 'dedupe_key'])]
class WingmanAction extends Model
{
    /** @use HasFactory<WingmanActionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
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
     * Where it stands: proposed, reviewing (waiting for the "sí"), running
     * (confirmed, within the undo window or executing), done, failed or
     * dismissed. Cancelled, undone or expired, it is proposed again.
     */
    public function state(): string
    {
        if ($this->dismissed_at !== null) {
            return 'dismissed';
        }

        return match ($this->pendingAction?->status) {
            ActionStatus::Pending => 'reviewing',
            ActionStatus::Confirmed, ActionStatus::Executing => 'running',
            ActionStatus::Executed => 'done',
            ActionStatus::Failed => 'failed',
            default => 'proposed',
        };
    }

    /**
     * What the meeting's page and the assistant see of it.
     *
     * @return array<string, mixed>
     */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'title' => $this->title,
            'detail' => $this->detail,
            'type' => $this->type,
            'label' => $this->label,
            'state' => $this->state(),
            'action' => in_array($this->state(), ['reviewing', 'running', 'done', 'failed'], true) ? $this->pendingAction?->toState() : null,
        ];
    }
}
