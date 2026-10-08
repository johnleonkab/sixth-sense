<?php

namespace App\Voice\Tools\Actions;

use App\Actions\ActionStatus;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Read-only: what really happened to a proposed change. The assistant can not
 * confirm, cancel or undo; it only reports the truth.
 */
class GetActionStatus implements VoiceTool
{
    public function name(): string
    {
        return 'get_action_status';
    }

    public function description(): string
    {
        return 'Consulta en qué estado está un cambio que propusiste (pending_action_id). '
            .'Úsala SIEMPRE antes de decir si un cambio se va a aplicar, se aplicó, se canceló o se deshizo: la app recoge la respuesta del usuario por su cuenta y tú no puedes suponerla. '
            .'Di al usuario lo que indica "say", con tus palabras pero sin afirmar nada más.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pending_action_id' => ['type' => 'integer', 'description' => 'El pending_action_id de la propuesta.'],
            ],
            'required' => ['pending_action_id'],
        ];
    }

    public function rules(): array
    {
        return ['pending_action_id' => ['required', 'integer']];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $action = $user->pendingActions()->find($arguments['pending_action_id']);

        if ($action === null) {
            return ToolResult::error('No existe esa propuesta.');
        }

        return new ToolResult([
            'pending_action_id' => $action->id,
            'status' => $action->status->value,
            'say' => $this->say($action->status),
            'meaning' => match ($action->status) {
                ActionStatus::Pending => 'Sigue esperando su respuesta: no se ha hecho nada.',
                ActionStatus::Confirmed => 'Confirmado pero AÚN NO aplicado: se aplicará en '.max(0, (int) ceil(now()->diffInSeconds($action->undo_until))).' segundos y todavía puede decir "deshaz". No digas que ya está hecho.',
                ActionStatus::Executing => 'Se está aplicando ahora mismo.',
                ActionStatus::Executed => 'Aplicado.',
                ActionStatus::Cancelled => 'Cancelado: no se ha hecho nada.',
                ActionStatus::Undone => 'Deshecho a tiempo: no se ha hecho nada.',
                ActionStatus::Expired => 'Caducado sin respuesta: no se ha hecho nada.',
                ActionStatus::Failed => 'Ha fallado: '.$action->error,
            },
        ]);
    }

    /**
     * What to tell the person, so the assistant never claims more than happened.
     */
    private function say(ActionStatus $status): string
    {
        return match ($status) {
            ActionStatus::Pending => '¿Lo hago?',
            ActionStatus::Confirmed => 'Hecho en unos segundos. Si quieres anularlo, di «deshaz».',
            ActionStatus::Executing => 'Haciéndolo ahora mismo.',
            ActionStatus::Executed => 'Hecho.',
            ActionStatus::Cancelled => 'Cancelado, no he hecho nada.',
            ActionStatus::Undone => 'Deshecho, no se ha hecho nada.',
            ActionStatus::Expired => 'Esa propuesta ha caducado; si quieres, la preparo de nuevo.',
            ActionStatus::Failed => 'No ha salido bien.',
        };
    }
}
