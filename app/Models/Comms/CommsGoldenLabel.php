<?php

namespace App\Models\Comms;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'kind',
    'message_id',
    'segment_id',
    'label',
    'expected',
    'evidence_event_ids',
    'people',
    'topics',
    'source_event_id',
])]
class CommsGoldenLabel extends Model
{
    protected $table = 'comms_golden_labels';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected' => 'array',
            'evidence_event_ids' => 'array',
            'people' => 'array',
            'topics' => 'array',
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
