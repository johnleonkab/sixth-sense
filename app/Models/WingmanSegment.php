<?php

namespace App\Models;

use Database\Factories\WingmanSegmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something said in a call: who said it (the person themselves, or someone
 * else by name) and what, as Meet's captions gave it.
 */
#[Fillable(['client_id', 'speaker', 'is_me', 'text', 'final', 'noted', 'watched', 'spoken_at'])]
class WingmanSegment extends Model
{
    /** @use HasFactory<WingmanSegmentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_me' => 'boolean',
            'final' => 'boolean',
            'noted' => 'boolean',
            'watched' => 'boolean',
            'spoken_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<WingmanMeeting, $this>
     */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(WingmanMeeting::class, 'wingman_meeting_id');
    }
}
