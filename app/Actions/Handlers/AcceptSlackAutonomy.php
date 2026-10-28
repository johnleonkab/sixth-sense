<?php

namespace App\Actions\Handlers;

use App\Actions\ActionHandler;
use App\Alerts\Intents\AutonomyLevel;
use App\Alerts\Intents\SlackAction;
use App\Alerts\Intents\SlackAutonomy;
use App\Models\PendingAction;
use RuntimeException;

/**
 * The person said yes to raising one Slack action a step.
 */
class AcceptSlackAutonomy implements ActionHandler
{
    public function __construct(private SlackAutonomy $autonomy) {}

    public function execute(PendingAction $action): array
    {
        $kind = SlackAction::parse($action->payload['action'] ?? null);
        $level = AutonomyLevel::tryFrom((string) ($action->payload['level'] ?? ''));

        if ($kind === null || $level === null) {
            throw new RuntimeException('Ese ascenso ya no está.');
        }

        $this->autonomy->promote($action->user, $kind, $level);

        return ['action' => $kind->value, 'level' => $level->value];
    }
}
