<?php

namespace App\Models;

use Database\Factories\MeetingReminderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * When to remind the user about an upcoming meeting, and what to prepare.
 */
#[Fillable(['event_key', 'event_ref', 'title', 'starts_at', 'remind_at', 'lead_minutes', 'needs_preparation', 'preparation', 'payload', 'notified_at'])]
class MeetingReminder extends Model
{
    /** @use HasFactory<MeetingReminderFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'remind_at' => 'immutable_datetime',
            'needs_preparation' => 'boolean',
            'payload' => 'array',
            'notified_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
