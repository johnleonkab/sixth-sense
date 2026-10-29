<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call to a model: what for, which one, what it took and cost.
 */
#[Fillable(['user_id', 'task', 'provider', 'model', 'status', 'input_tokens', 'cached_tokens', 'output_tokens', 'reasoning_tokens', 'latency_ms', 'cost', 'error'])]
class AiUsage extends Model
{
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['cost' => 'float', 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
