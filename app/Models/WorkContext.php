<?php

namespace App\Models;

use App\WorkContext\WorkContextStatus;
use Database\Factories\WorkContextFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the person is working on lately, built every night from their
 * services (read only), for the voice assistant to start each conversation
 * knowing it.
 */
#[Fillable(['enabled', 'status', 'summary', 'digest', 'sources', 'error', 'started_at', 'generated_at'])]
class WorkContext extends Model
{
    /** @use HasFactory<WorkContextFactory> */
    use HasFactory;

    /** Older than this, it no longer describes the present: the assistant does not get it. */
    public const FRESH_FOR_HOURS = 72;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'enabled' => false,
        'status' => 'idle',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'status' => WorkContextStatus::class,
            'digest' => 'array',
            'sources' => 'array',
            'started_at' => 'immutable_datetime',
            'generated_at' => 'immutable_datetime',
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
     * Whether the assistant should get it: on, built, and recent.
     */
    public function usable(): bool
    {
        return $this->enabled
            && filled($this->summary)
            && $this->generated_at?->greaterThan(now()->subHours(self::FRESH_FOR_HOURS)) === true;
    }
}
