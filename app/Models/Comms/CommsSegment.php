<?php

namespace App\Models\Comms;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'window_id',
    'message_ids',
    'new_message_ids',
    'participants',
    'restated',
    'unresolved_refs',
    'unsegmented',
    'decision_event_id',
])]
class CommsSegment extends Model
{
    protected $table = 'comms_segments';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'message_ids' => 'array',
            'new_message_ids' => 'array',
            'participants' => 'array',
            'unresolved_refs' => 'array',
            'unsegmented' => 'boolean',
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
     * @return BelongsTo<CommsWindow, $this>
     */
    public function window(): BelongsTo
    {
        return $this->belongsTo(CommsWindow::class, 'window_id');
    }
}
