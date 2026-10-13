<?php

namespace App\Models;

use App\Wingman\MeetingStatus;
use Database\Factories\WingmanMeetingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Google Meet call of the person's, found in their calendar: who is in it,
 * what it is about, and what Wingman gathered before it starts.
 */
#[Fillable(['event_key', 'event_ref', 'meeting_code', 'meeting_url', 'title', 'account', 'organizer', 'starts_at', 'ends_at', 'attendees', 'description', 'status', 'joined_at', 'left_at', 'context', 'context_prepared_at'])]
class WingmanMeeting extends Model
{
    /** @use HasFactory<WingmanMeetingFactory> */
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
            'ends_at' => 'immutable_datetime',
            'attendees' => 'array',
            'status' => MeetingStatus::class,
            'context' => 'array',
            'context_prepared_at' => 'immutable_datetime',
            'joined_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
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
     * What was said in it, in order.
     *
     * @return HasMany<WingmanSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(WingmanSegment::class)->orderBy('spoken_at')->orderBy('id');
    }

    /**
     * The Meet code in a link (https://meet.google.com/abc-defg-hij), if it is one.
     */
    public static function codeIn(?string $url): ?string
    {
        return $url !== null && preg_match('~meet\.google\.com/([a-z]{3}-[a-z]{4}-[a-z]{3})~i', $url, $match) ? strtolower($match[1]) : null;
    }
}
