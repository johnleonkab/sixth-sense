<?php

namespace App\Models\Comms;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'segment_id',
    'topic_subject',
    'intent_id',
    'outcome',
    'certainty',
    'agreement',
    'alternative',
    'quote',
    'run_event_ids',
    'event_id',
])]
class CommsLinkDecision extends Model
{
    protected $table = 'comms_link_decisions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'alternative' => 'array',
            'run_event_ids' => 'array',
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
