<?php

namespace App\Comms\Gate;

use App\Models\Comms\CommsProposal;
use App\Models\User;

/**
 * A proposal nobody decided within 72 hours leaves the digest.
 */
class ProposalExpiry
{
    public function expire(User $user): int
    {
        return CommsProposal::query()
            ->whereBelongsTo($user)
            ->whereIn('status', [ProposalStatus::Pending, ProposalStatus::Edited])
            ->where('created_at', '<=', now()->subHours(72))
            ->update(['status' => ProposalStatus::Expired->value, 'updated_at' => now()]);
    }
}
