<?php

namespace App\Models;

use App\Alerts\InboxCandidate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Slack triage, kept so a later poke or an unanswered question in a
 * channel she owns can be found. The reason and the message stay here.
 */
#[Fillable(['user_id', 'inbox_item_id', 'external_id', 'action', 'place', 'owner', 'reason', 'uncertain', 'has_target', 'target', 'already_answered', 'policy_present', 'thread_key', 'channel', 'candidate', 'draft_text', 'edited', 'autonomy', 'promoted_at', 'settled_at', 'missed_at', 'confirmed_at'])]
class SlackTriageDecision extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'uncertain' => 'boolean',
            'has_target' => 'boolean',
            'already_answered' => 'boolean',
            'policy_present' => 'boolean',
            'candidate' => 'array',
            'promoted_at' => 'datetime',
            'settled_at' => 'datetime',
            'missed_at' => 'datetime',
            'confirmed_at' => 'datetime',
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
     * Rules proposed from this decision.
     *
     * @return HasMany<SlackPreferenceRule, $this>
     */
    public function preferenceRules(): HasMany
    {
        return $this->hasMany(SlackPreferenceRule::class);
    }

    /**
     * @return BelongsTo<InboxItem, $this>
     */
    public function inboxItem(): BelongsTo
    {
        return $this->belongsTo(InboxItem::class);
    }

    public function candidate(): InboxCandidate
    {
        return InboxCandidate::fromArray($this->candidate);
    }
}
