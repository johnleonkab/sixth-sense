<?php

namespace App\Models;

use App\Voice\Tools\Canvas;
use Database\Factories\AgentProductionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something an agent produced, kept under its own reference: a view, a draft
 * or proposal (with its canvas), or a fact with its source. The voice shows it
 * by that reference and never passes its content.
 */
#[Fillable(['ref', 'user_id', 'agent_run_id', 'kind', 'summary', 'canvas', 'beside', 'pending_action_id'])]
class AgentProduction extends Model
{
    /** @use HasFactory<AgentProductionFactory> */
    use HasFactory;

    public const VIEW = 'view';

    public const PROPOSAL = 'proposal';

    public const FACT = 'fact';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'canvas' => 'array',
            'beside' => 'array',
        ];
    }

    public function toCanvas(): ?Canvas
    {
        return self::canvasFrom($this->canvas);
    }

    public function besideCanvas(): ?Canvas
    {
        return self::canvasFrom($this->beside);
    }

    /**
     * @param  ?array{view?: string, props?: array<string, mixed>, layout?: string}  $data
     */
    private static function canvasFrom(?array $data): ?Canvas
    {
        return isset($data['view']) ? new Canvas($data['view'], $data['props'] ?? [], $data['layout'] ?? 'focus') : null;
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
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    /**
     * @return BelongsTo<PendingAction, $this>
     */
    public function pendingAction(): BelongsTo
    {
        return $this->belongsTo(PendingAction::class);
    }
}
