<?php

namespace App\Voice\Tools\Slack;

use App\Alerts\Intents\AutonomyLevel;
use App\Alerts\Intents\SlackAction;
use App\Alerts\Intents\SlackAutonomy;
use App\Models\User;
use App\Voice\OpenUi\Views\SlackViews;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use RuntimeException;

/**
 * Drop one Slack action a step. It applies at once, with no sample.
 */
class LowerSlackAutonomy implements VoiceTool
{
    public function __construct(private SlackAutonomy $autonomy) {}

    public function name(): string
    {
        return 'lower_slack_autonomy';
    }

    public function description(): string
    {
        return 'Baja el nivel de una acción de Slack al momento, sin umbral. action: reply, redirect o task. '
            .'level: watch (ver) o draft (proponer). Hacer baja a proponer; proponer baja a ver.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['reply', 'redirect', 'task']],
                'level' => ['type' => 'string', 'enum' => ['watch', 'draft']],
            ],
            'required' => ['action', 'level'],
        ];
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:reply,redirect,task'],
            'level' => ['required', 'in:watch,draft'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $action = SlackAction::parse($arguments['action']);
        $level = AutonomyLevel::tryFrom($arguments['level']);

        if ($action === null || $level === null) {
            return ToolResult::error('Esa categoría no existe.');
        }

        try {
            $this->autonomy->lower($user, $action, $level);
        } catch (RuntimeException $exception) {
            return ToolResult::error($exception->getMessage());
        }

        $page = $this->autonomy->present($user);

        return new ToolResult(
            output: ['level' => $level->value, 'label' => $action->label()],
            canvas: SlackViews::autonomy($user, $page),
        );
    }
}
