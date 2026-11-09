<?php

namespace App\Models\Comms;

use App\Comms\Events\Source;
use App\Models\User;
use Database\Factories\Comms\CommsMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'event_id',
    'source',
    'external_id',
    'channel_ref',
    'thread_ref',
    'author_person_id',
    'recipient_person_ids',
    'direction',
    'text',
    'links',
    'attachments',
    'flags',
    'deleted_at',
    'occurred_at',
])]
class CommsMessage extends Model
{
    /** @use HasFactory<CommsMessageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => Source::class,
            'recipient_person_ids' => 'array',
            'links' => 'array',
            'attachments' => 'array',
            'flags' => 'array',
            'deleted_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
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
     * @return BelongsTo<CommsEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(CommsEvent::class, 'event_id');
    }

    /**
     * @return BelongsTo<CommsPerson, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(CommsPerson::class, 'author_person_id');
    }
}
