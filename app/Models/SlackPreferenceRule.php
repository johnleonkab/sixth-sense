<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Slack attention rule the person stated. It stays proposed until she says
 * yes, then it sits above the history learned from how she has answered.
 */
#[Fillable(['user_id', 'slack_triage_decision_id', 'pending_action_id', 'correction', 'status', 'place', 'channel', 'actor', 'target', 'words', 'body', 'accepted_at'])]
class SlackPreferenceRule extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'words' => 'array',
            'accepted_at' => 'datetime',
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
     * @return BelongsTo<SlackTriageDecision, $this>
     */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(SlackTriageDecision::class, 'slack_triage_decision_id');
    }
}
