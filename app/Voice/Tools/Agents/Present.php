<?php

namespace App\Voice\Tools\Agents;

use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Shows something an agent produced, by its reference: the voice never passes
 * the content itself, so what is on screen is what the agent made.
 */
class Present implements VoiceTool
{
    public function name(): string
    {
        return 'present';
    }

    public function description(): string
    {
        return 'Vuelve a mostrar algo que han preparado los agentes (una vista, un borrador, una propuesta) por su referencia (prod_…), de una nota de resultado o de la pantalla.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['ref' => ['type' => 'string', 'description' => 'La referencia prod_… de lo que hay que mostrar.']],
            'required' => ['ref'],
        ];
    }

    public function rules(): array
    {
        return ['ref' => ['required', 'string', 'max:40']];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $production = $user->agentProductions()->where('ref', $arguments['ref'])->first();

        if ($production === null || $production->toCanvas() === null) {
            return ToolResult::error('No hay nada preparado con esa referencia. Usa solo las referencias de una nota de resultado.');
        }

        return new ToolResult(
            output: ['shown_on_screen' => true, 'what' => $production->summary],
            canvas: $production->toCanvas(),
            beside: $production->besideCanvas(),
        );
    }
}
