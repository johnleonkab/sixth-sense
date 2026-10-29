<?php

namespace App\Models;

use Database\Factories\SceneStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of a scene: the read, or one proposal that came from it.
 */
#[Fillable(['scene_id', 'layer', 'key', 'status', 'payload', 'error', 'pending_action_id'])]
class SceneStep extends Model
{
    /** @use HasFactory<SceneStepFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'layer' => 'integer',
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Scene, $this>
     */
    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }

    /**
     * @return BelongsTo<PendingAction, $this>
     */
    public function action(): BelongsTo
    {
        return $this->belongsTo(PendingAction::class, 'pending_action_id');
    }
}
