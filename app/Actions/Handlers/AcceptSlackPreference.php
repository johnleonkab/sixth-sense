<?php

namespace App\Actions\Handlers;

use App\Actions\ActionHandler;
use App\Alerts\Intents\SlackPreference;
use App\Models\PendingAction;
use App\Models\SlackPreferenceRule;
use RuntimeException;

/**
 * The person said yes: the proposed attention rule enters her profile.
 */
class AcceptSlackPreference implements ActionHandler
{
    public function __construct(private SlackPreference $preferences) {}

    public function execute(PendingAction $action): array
    {
        $rule = SlackPreferenceRule::query()
            ->where('user_id', $action->user_id)
            ->find($action->payload['rule_id'] ?? null);

        if ($rule === null) {
            throw new RuntimeException('Esa regla ya no está.');
        }

        $this->preferences->accept($rule);

        return ['rule' => $rule->body];
    }
}
