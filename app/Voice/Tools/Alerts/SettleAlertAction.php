<?php

namespace App\Voice\Tools\Alerts;

use App\Alerts\Actions\AlertActions;
use App\Alerts\AlertFollowUp;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Settle what an alert's action is missing: a doubt ("sí" prepares it, "no"
 * takes it off), or, for a call, who takes part and how long it lasts.
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
            .'answer "yes" la prepara (la tarea o la llamada se propone en el calendario), "no" la quita del aviso. action: la clave de la acción (a0, a1…). '
            .'Para una llamada, people (quiénes van, por nombre) y duration_minutes (15, 30, 45 o 60) cuando el usuario los dice: se busca hueco de nuevo.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'alert_id' => ['type' => 'integer'],
                'action' => ['type' => 'string'],
                'answer' => ['type' => 'string', 'enum' => ['yes', 'no']],
                'people' => ['type' => 'array', 'items' => ['type' => 'string']],
                'duration_minutes' => ['type' => 'integer'],
            ],
            'required' => ['alert_id', 'action'],
        ];
    }

    public function rules(): array
    {
        return [
            'alert_id' => ['required', 'integer'],
            'action' => ['required', 'string', 'max:10'],
            'answer' => ['nullable', 'in:yes,no'],
            'people' => ['nullable', 'array', 'min:1', 'max:10'],
            'people.*' => ['string', 'max:120'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:240'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $item = $user->inboxItems()->find($arguments['alert_id']);

        if ($item === null || ! collect($item->proposal['actions'] ?? [])->contains('key', $arguments['action'])) {
            return ToolResult::error('No encuentro esa acción en el aviso.');
        }

        $answer = $arguments['answer'] ?? null;
        $changes = array_filter(['people' => $arguments['people'] ?? null, 'duration_minutes' => $arguments['duration_minutes'] ?? null], fn (mixed $value): bool => $value !== null);
        $this->actions->settle($item, $arguments['action'], $answer === null ? null : $answer === 'yes', $changes);
        $item->refresh();

        return new ToolResult(
            output: ['say' => match (true) {
                $answer === 'no' => 'Vale, lo quito.',
                $changes !== [] => 'Vale, busco hueco otra vez.',
                default => 'Vale, lo preparo.',
            }],
            canvas: $this->reviewCanvas($item, $this->followUp->brief($item)),
        );
    }
}
