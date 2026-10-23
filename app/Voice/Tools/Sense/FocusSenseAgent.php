<?php

namespace App\Voice\Tools\Sense;

use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Hands the turn to one synced agent, or back to the general assistant.
 * The screen shows who is in use.
 */
class FocusSenseAgent implements VoiceTool
{
    public function name(): string
    {
        return 'focus_sense_agent';
    }

    public function description(): string
    {
        return 'Elige el agente del archivo que debe llevar este turno, cuando el tema encaja con su "cuándo". '
            .'Devuelve sus instrucciones: síguelas a partir de aquí. Con slug vacío vuelves al asistente general y quitas el agente de la pantalla.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'Slug del agente, o vacío para volver al asistente general.'],
            ],
            'required' => ['slug'],
        ];
    }

    public function rules(): array
    {
        return [
            'slug' => ['present', 'nullable', 'string', 'max:100'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $slug = trim((string) ($arguments['slug'] ?? ''));

        if ($slug === '') {
            return new ToolResult(
                output: ['agent' => null, 'instructions' => 'Vuelves al asistente general. No sigas las instrucciones de un agente.'],
                canvas: new Canvas('sense.agent', ['name' => null]),
            );
        }

        $agent = $user->senseAgents()->where('slug', $slug)->first();

        if ($agent === null) {
            return ToolResult::error("No hay un agente «{$slug}». Sincroniza el archivo si acabas de crearlo.");
        }

        return new ToolResult(
            output: [
                'agent' => $agent->slug,
                'name' => $agent->name,
                'instructions' => $agent->instructions !== '' ? $agent->instructions : $agent->purpose,
            ],
            canvas: new Canvas('sense.agent', [
                'name' => $agent->name,
                'purpose' => $agent->purpose,
            ]),
        );
    }
}
