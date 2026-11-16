<?php

namespace App\Comms\Gate;

use App\Actions\ActionStatus;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\User;

/**
 * An update that arrives after approval and before the send puts the proposal
 * back in Needs you. A send that already happened is not done again.
 */
class HeldSend
{
    public function after(User $user, int $intentId, ?string $before, ?string $after): void
    {
        $change = $this->change($before, $after);
        $proposals = CommsProposal::query()
            ->where('user_id', $user->id)
            ->where('intent_id', $intentId)
            ->whereIn('status', [ProposalStatus::Approved, ProposalStatus::Executed])
            ->orderBy('id')
            ->get();

        foreach ($proposals as $proposal) {
            if ($proposal->status === ProposalStatus::Executed || $proposal->pendingAction?->status === ActionStatus::Executed) {
                $this->question($user, $intentId, $proposal, $change);

                continue;
            }

            $this->hold($proposal, $change);
        }
    }

    private function hold(CommsProposal $proposal, ?string $change): void
    {
        $action = $proposal->pendingAction;

        if ($action !== null && $action->status === ActionStatus::Confirmed) {
            $action->update(['status' => ActionStatus::Undone]);
        }

        $payload = is_array($proposal->payload) ? $proposal->payload : [];
        $payload['change'] = $change ?? 'Hay un cambio nuevo antes del envío.';
        $proposal->payload = $payload;
        $proposal->status = ProposalStatus::Pending;
        $proposal->decided_at = null;
        $proposal->save();
    }

    private function question(User $user, int $intentId, CommsProposal $proposal, ?string $change): void
    {
        if ($change === null) {
            return;
        }

        CommsQuestion::query()->updateOrCreate(
            ['user_id' => $user->id, 'key' => 'stale:'.$proposal->id],
            [
                'intent_id' => $intentId,
                'text' => 'Esto ya se envió. '.$change,
                'context' => ['proposal_id' => $proposal->id],
                'options' => [],
            ],
        );
    }

    private function change(?string $before, ?string $after): ?string
    {
        if ($before === $after) {
            return null;
        }

        return 'La fecha límite pasa del '.($before ?? 'sin fecha').' al '.($after ?? 'sin fecha').'.';
    }
}
