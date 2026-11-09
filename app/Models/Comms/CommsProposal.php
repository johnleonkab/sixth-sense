<?php

namespace App\Models\Comms;

use App\Comms\Gate\ActionClass;
use App\Comms\Gate\PolicyOutcome;
use App\Comms\Gate\ProposalStatus;
use App\Models\PendingAction;
use App\Models\User;
use Database\Factories\Comms\CommsProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'key',
    'run_id',
    'intent_id',
    'action_class',
    'target',
    'payload',
    'source_event_ids',
    'verification',
    'allows',
    'states_product_facts',
    'commits_date',
    'user_initiated',
    'policy_outcome',
    'deny_reason',
    'status',
    'external_id',
    'decided_at',
    'executed_at',
])]
class CommsProposal extends Model
{
    /** @use HasFactory<CommsProposalFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action_class' => ActionClass::class,
            'target' => 'array',
            'payload' => 'array',
            'source_event_ids' => 'array',
            'verification' => 'array',
            'allows' => 'array',
            'states_product_facts' => 'boolean',
            'commits_date' => 'boolean',
            'user_initiated' => 'boolean',
            'policy_outcome' => PolicyOutcome::class,
            'status' => ProposalStatus::class,
            'decided_at' => 'immutable_datetime',
            'executed_at' => 'immutable_datetime',
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
     * @return BelongsTo<CommsIntent, $this>
     */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(CommsIntent::class, 'intent_id');
    }

    /**
     * @return HasOne<PendingAction, $this>
     */
    public function pendingAction(): HasOne
    {
        return $this->hasOne(PendingAction::class, 'proposal_id');
    }
}
