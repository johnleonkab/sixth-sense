<?php

namespace App\Models;

use App\Actions\ActionStatus;
use Database\Factories\PendingActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change to an external service the assistant proposed. It only happens
 * after the person confirms, once the undo window has closed.
 */
#[Fillable(['type', 'label', 'payload', 'status', 'expires_at', 'undo_until', 'executed_at', 'result', 'error'])]
class PendingAction extends Model
{
    /** @use HasFactory<PendingActionFactory> */
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
            'result' => 'array',
            'status' => ActionStatus::class,
            'expires_at' => 'immutable_datetime',
            'undo_until' => 'immutable_datetime',
            'executed_at' => 'immutable_datetime',
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
     * What the screen needs to show the action's state.
     *
     * @return array{id: int, type: string, label: string, status: string, expires_at: string, undo_until: ?string, result: ?array<string, mixed>, error: ?string}
     */
    public function toState(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'label' => $this->label,
            'status' => $this->status->value,
            'expires_at' => $this->expires_at->toIso8601String(),
            'undo_until' => $this->undo_until?->toIso8601String(),
            'result' => $this->result,
            'error' => $this->error,
        ];
    }
}
