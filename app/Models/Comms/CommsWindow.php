<?php

namespace App\Models\Comms;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'source',
    'group_ref',
    'kind',
    'message_ids',
    'opened_at',
    'last_message_at',
    'settled_event_id',
    'state',
])]
class CommsWindow extends Model
{
    protected $table = 'comms_windows';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'message_ids' => 'array',
            'opened_at' => 'immutable_datetime',
            'last_message_at' => 'immutable_datetime',
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
