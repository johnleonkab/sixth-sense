<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An evaluation case that came from a correction of the person: the message
 * and what it really asked. It joins its agent's set in database/evals, so a
 * new model or prompt is scored on what failed before.
 */
#[Fillable(['user_id', 'agent', 'case', 'proposal_outcome_id'])]
class LearnedEvalCase extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['case' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
