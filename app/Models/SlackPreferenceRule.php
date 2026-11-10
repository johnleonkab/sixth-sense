<?php

namespace App\Models;

use App\Alerts\Intents\SlackPreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Slack attention rule the person stated. It stays proposed until she says
 * yes, then it sits above the history learned from how she has answered.
 */
#[Fillable(['user_id', 'slack_triage_decision_id', 'pending_action_id', 'correction', 'status', 'place', 'channel', 'actor', 'target', 'words', 'accepted_at'])]
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
     * The rule as she would say it. Once accepted it is a fact in the
     * context store; this row keeps what code needs to apply it.
     */
    public function sentence(): string
    {
        return app(SlackPreference::class)->phrase($this->correction, $this->channel, $this->actor, $this->words ?? []);
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
