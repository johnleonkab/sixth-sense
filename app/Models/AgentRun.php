<?php

namespace App\Models;

use Database\Factories\AgentRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of an agent: the task, each step it took, what it answered and what
 * it cost. The orchestrator's run is the parent of its agents' runs, so models
 * can be compared agent by agent with real data.
 */
#[Fillable([
    'user_id', 'parent_id', 'agent', 'position', 'task', 'status', 'plan', 'steps', 'say', 'refs', 'provider', 'model',
    'input_tokens', 'output_tokens', 'reasoning_tokens', 'cost_usd', 'latency_ms', 'error', 'started_at', 'finished_at',
])]
class AgentRun extends Model
{
    /** @use HasFactory<AgentRunFactory> */
    use HasFactory;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plan' => 'array',
            'steps' => 'array',
            'refs' => 'array',
            'cost_usd' => 'float',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function finished(): bool
    {
        return in_array($this->status, [self::DONE, self::FAILED], true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<AgentRun, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'parent_id');
    }

    /**
     * @return HasMany<AgentRun, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(AgentRun::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<AgentProduction, $this>
     */
    public function productions(): HasMany
    {
        return $this->hasMany(AgentProduction::class)->orderBy('id');
    }
}
