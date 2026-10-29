<?php

namespace App\Voice\Tools\Sense;

use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Hands the turn to one synced agent, or back to the general assistant, as
 * Claude Code delegates to a subagent by its description. The screen shows
 * who is in use.
 */
class FocusSenseAgent implements VoiceTool
{
    public function name(): string
    {
        return 'focus_sense_agent';
    }

    public function description(): string
    {
        return 'Elige el agente del archivo que debe llevar este turno, cuando el tema encaja con su description. '
            .'Devuelve sus instrucciones y las skills que precarga: síguelas a partir de aquí. Con name vacío vuelves al asistente general y quitas el agente de la pantalla.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'name del agente, o vacío para volver al asistente general.'],
            ],
            'required' => ['name'],
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['present', 'nullable', 'string', 'max:255'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $name = trim((string) ($arguments['name'] ?? ''));

        if ($name === '') {
            return new ToolResult(
                output: ['agent' => null, 'instructions' => 'Vuelves al asistente general. No sigas las instrucciones de un agente.'],
                canvas: new Canvas('sense.agent', ['name' => null]),
            );
        }

        $agent = $user->senseAgents()->where('slug', $name)->first();

        if ($agent === null) {
            return ToolResult::error("No hay un agente «{$name}». Sincroniza el archivo si acabas de crearlo.");
        }

        return new ToolResult(
            output: [
                'agent' => $agent->slug,
                'name' => $agent->name,
                'instructions' => $agent->briefing(),
            ],
            canvas: new Canvas('sense.agent', [
                'name' => $agent->name,
                'purpose' => $agent->description,
            ]),
        );
    }
}
