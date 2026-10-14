<?php

namespace App\Wingman\Closing;

use App\Actions\Actions;
use App\Models\WingmanAction;

/**
 * The things left to do after a call, as the person handles them. Reviewing
 * one makes it a pending change with the usual "sí" and undo window; one
 * already waiting is reused instead of proposed twice.
 */
class MeetingActions
{
    public function __construct(private Actions $actions) {}

    public function review(WingmanAction $action): WingmanAction
    {
        if (in_array($action->state(), ['reviewing', 'running', 'done'], true)) {
            return $action;
        }

        $pending = $this->actions->propose($action->meeting->user, $action->type, $action->label, $action->payload);
        $action->update(['pending_action_id' => $pending->id, 'dismissed_at' => null]);

        return $action->refresh();
    }

    public function dismiss(WingmanAction $action, bool $dismissed): WingmanAction
    {
        if (! in_array($action->state(), ['running', 'done'], true)) {
            $action->update(['dismissed_at' => $dismissed ? now() : null]);
        }

        return $action->refresh();
    }
}
