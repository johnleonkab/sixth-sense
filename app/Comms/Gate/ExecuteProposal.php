<?php

namespace App\Comms\Gate;

use App\Actions\ActionHandler;
use App\Models\PendingAction;
use Throwable;

/**
 * Runs a confirmed proposal once. The pending-action job is the only caller.
 */
class ExecuteProposal implements ActionHandler
{
    public function __construct(
        private WriteTools $tools,
        private ProposalEcho $echo,
    ) {}

    public function execute(PendingAction $action): array
    {
        $proposal = $action->proposal;

        if ($proposal === null || $action->user === null) {
            return [];
        }

        if ($proposal->status === ProposalStatus::Executed && filled($proposal->external_id)) {
            return ['external_id' => $proposal->external_id];
        }

        try {
            $externalId = $this->tools->send($action->user, $proposal);
        } catch (Throwable $exception) {
            $proposal->status = ProposalStatus::Failed;
            $proposal->save();

            throw $exception;
        }

        $proposal->external_id = $externalId;
        $proposal->status = ProposalStatus::Executed;
        $proposal->executed_at = now();
        $proposal->save();

        $this->echo->record($action->user, $proposal, $externalId);

        return ['external_id' => $externalId];
    }
}
