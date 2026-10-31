<?php

namespace App\Voice\Tools\Alerts;

use App\Alerts\Actions\AlertActions;
use App\Alerts\AlertFollowUp;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Settle a doubt on an alert's action: "sí" prepares it, "no" takes it off.
 */
class SettleAlertAction implements VoiceTool
{
    use DescribesAlerts;

    public function __construct(private AlertActions $actions, private AlertFollowUp $followUp) {}

    public function name(): string
    {
        return 'settle_alert_action';
    }

    public function description(): string
    {
        return 'Cuando un aviso tiene una acción en duda (una llamada, una tarea o una pregunta que no está claro que haga falta) y el usuario contesta: '
            .'answer "yes" la prepara (la tarea o la llamada se propone en el calendario), "no" la quita del aviso. action: la clave de la acción (a0, a1…).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'alert_id' => ['type' => 'integer'],
                'action' => ['type' => 'string'],
                'answer' => ['type' => 'string', 'enum' => ['yes', 'no']],
            ],
            'required' => ['alert_id', 'action', 'answer'],
        ];
    }

    public function rules(): array
    {
        return [
            'alert_id' => ['required', 'integer'],
            'action' => ['required', 'string', 'max:10'],
            'answer' => ['required', 'in:yes,no'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $item = $user->inboxItems()->find($arguments['alert_id']);

        if ($item === null || ! collect($item->proposal['actions'] ?? [])->contains('key', $arguments['action'])) {
            return ToolResult::error('No encuentro esa acción en el aviso.');
        }

        $yes = $arguments['answer'] === 'yes';
        $this->actions->settle($item, $arguments['action'], $yes);
        $item->refresh();

        return new ToolResult(
            output: ['say' => $yes ? 'Vale, lo preparo.' : 'Vale, lo quito.'],
            canvas: $this->reviewCanvas($item, $this->followUp->brief($item)),
        );
    }
}
