<?php

namespace App\Models\Comms;

use App\Models\User;
use Database\Factories\Comms\CommsIntentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'key',
    'what',
    'state',
    'settle_reason',
    'deadline',
    'deadline_text',
    'expects',
    'importance',
    'importance_override',
    'importance_override_until',
    'flags',
    'opened_event_id',
    'settled_event_id',
    'last_change_event_id',
    'last_change_summary',
    'acknowledged_at',
    'settled_at',
    'last_activity_at',
])]
class CommsIntent extends Model
{
    /** @use HasFactory<CommsIntentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deadline' => 'date',
            'importance' => 'integer',
            'importance_override' => 'integer',
            'importance_override_until' => 'immutable_datetime',
            'flags' => 'array',
            'acknowledged_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'last_activity_at' => 'immutable_datetime',
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
     * @return HasMany<CommsIntentParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(CommsIntentParticipant::class, 'intent_id');
    }

    /**
     * @return HasMany<CommsConversationLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(CommsConversationLink::class, 'intent_id');
    }
}
