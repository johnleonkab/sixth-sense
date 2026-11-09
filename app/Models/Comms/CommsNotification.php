<?php

namespace App\Models\Comms;

use App\Models\User;
use Database\Factories\Comms\CommsNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'key',
    'kind',
    'urgency',
    'subject_type',
    'subject_id',
    'source_event_ids',
    'delivered_at',
    'seen_at',
    'dismissed_at',
    'feedback',
])]
class CommsNotification extends Model
{
    /** @use HasFactory<CommsNotificationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_event_ids' => 'array',
            'delivered_at' => 'immutable_datetime',
            'seen_at' => 'immutable_datetime',
            'dismissed_at' => 'immutable_datetime',
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
