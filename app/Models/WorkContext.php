<?php

namespace App\Models;

use App\WorkContext\WorkContextStatus;
use Database\Factories\WorkContextFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The nightly read of the person's services: whether it is on and how the
 * last one went. What it found is in the context store, as inferred facts.
 */
#[Fillable(['enabled', 'status', 'sources', 'error', 'started_at', 'generated_at'])]
class WorkContext extends Model
{
    /** @use HasFactory<WorkContextFactory> */
    use HasFactory;

    /** Older than this, the last read no longer describes the present. */
    public const FRESH_FOR_HOURS = 72;

    /** A build running longer than this died with its worker: it may be started again. */
    public const BUILD_STALE_AFTER_MINUTES = 15;

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
     * Whether the last read is on and recent.
     */
    public function recent(): bool
    {
        return $this->enabled
            && $this->generated_at?->greaterThan(now()->subHours(self::FRESH_FOR_HOURS)) === true;
    }

    /**
     * Whether a build is under way right now.
     */
    public function building(): bool
    {
        return $this->status === WorkContextStatus::Running
            && $this->started_at?->greaterThan(now()->subMinutes(self::BUILD_STALE_AFTER_MINUTES)) === true;
    }
}
