<?php

namespace App\Voice\Tools\Slack;

use App\Alerts\Intents\SlackAutonomy;
use App\Models\User;
use App\Voice\OpenUi\Views\SlackViews;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * The autonomy ladder and the five readings, on screen.
 */
class ShowSlackAutonomy implements VoiceTool
{
    public function __construct(private SlackAutonomy $autonomy) {}

    public function name(): string
    {
        return 'show_slack_autonomy';
    }

    public function description(): string
    {
        return 'Muestra el nivel de autonomía de Slack (ver, proponer, hacer) para responder, redirigir y crear tareas, '
            .'y los números de si se le escapa algo, si acierta y cuánto tarda. Úsala para "cómo de sola actúa Slack" o "el tablero de Slack".';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function rules(): array
    {
        return [];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $page = $this->autonomy->present($user);

        return new ToolResult(
            output: [
                'levels' => $page['levels'],
                'board' => $page['board'],
                'shown_on_screen' => true,
            ],
            canvas: SlackViews::autonomy($user, $page),
        );
    }
}
