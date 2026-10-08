<?php

namespace App\Voice\Tools\Docs;

use App\Models\PendingAction;

/**
 * What every proposal tells the assistant: it can not confirm by itself.
 */
trait ProposesChanges
{
    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function proposalOutput(PendingAction $action, array $details): array
    {
        return [
            'pending_action_id' => $action->id,
            'status' => 'esperando confirmación',
            ...$details,
            'shown_on_screen' => true,
            'next_step' => 'Es solo un borrador: descríbelo en condicional ("propongo…", "¿lo aplico?"), nunca como hecho. '
                .'La app recoge su "sí", "no" o "deshaz" directamente: tú no puedes confirmarlo. '
                .'Cuando responda, consulta get_action_status y cuenta exactamente lo que diga.',
        ];
    }
}
