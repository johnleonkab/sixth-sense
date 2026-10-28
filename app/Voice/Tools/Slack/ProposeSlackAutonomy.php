<?php

namespace App\Voice\Tools\Slack;

use App\Alerts\Intents\SlackAction;
use App\Alerts\Intents\SlackAutonomy;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Ask before raising a Slack action. The rise waits for "sí", and only if the sample allows it.
 */
class ProposeSlackAutonomy implements VoiceTool
{
    public function __construct(private SlackAutonomy $autonomy) {}

    public function name(): string
    {
        return 'propose_slack_autonomy';
    }

    public function description(): string
    {
        return 'Propone subir una acción de Slack (reply, redirect o task) al siguiente nivel. '
            .'No sube sola: espera el sí. A «hacer» solo se llega si la muestra de esa acción supera el umbral.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['reply', 'redirect', 'task'], 'description' => 'reply, redirect o task.'],
            ],
            'required' => ['action'],
        ];
    }

    public function rules(): array
    {
        return ['action' => ['required', 'in:reply,redirect,task']];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $action = SlackAction::parse($arguments['action']);

        if ($action === null) {
            return ToolResult::error('Esa categoría no existe.');
        }

        $pending = $this->autonomy->offer($user, $action);

        if ($pending === null) {
            return ToolResult::error($this->autonomy->blocked($user, $action));
        }

        $page = $this->autonomy->present($user);

        return new ToolResult(
            output: [
                'pending_action_id' => $pending->id,
                'label' => $pending->label,
                'needs_confirmation' => true,
            ],
            canvas: new Canvas('slack.autonomy', [
                ...$page,
                'action' => $pending->toState(),
            ]),
        );
    }
}
