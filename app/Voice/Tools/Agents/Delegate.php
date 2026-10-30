<?php

namespace App\Voice\Tools\Agents;

use App\Agents\AgentRegistry;
use App\Jobs\RunDelegation;
use App\Models\AgentRun;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Hands an ask to the agents: the orchestrator splits it and they do the
 * work in the background. The screen opens at once and fills when they are
 * done; the voice then says what they said, never content of its own.
 */
class Delegate implements VoiceTool
{
    public function __construct(private AgentRegistry $registry) {}

    public function name(): string
    {
        return 'delegate';
    }

    public function description(): string
    {
        return 'Pasa a los agentes todo lo que sea más que abrir o leer algo: responder o redactar (emails, Slack, reacciones, pasar algo a otra persona), '
            .'el calendario (convocar, mover, cancelar, agendar), documentos, Notion, Jira, el Archivo, servidores MCP o Analytics, gráficos y vistas, '
            .'o reunir contexto de varias fuentes. Una sola llamada: task es el encargo con las palabras de la persona y los ids que ya tengas de la pantalla. '
            .'No escribas tú el contenido. Di una frase corta («Lo preparo») y calla: el resultado llega solo, en una nota.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task' => ['type' => 'string', 'description' => 'El encargo, con las palabras de la persona y los ids de la pantalla que hagan falta.'],
                'agent' => ['type' => 'string', 'description' => 'Opcional: el agente, si está claro (comunicacion, agenda, investigacion, redaccion, integraciones, vistas, o archivo:<nombre> para un agente del Archivo).'],
            ],
            'required' => ['task'],
        ];
    }

    public function rules(): array
    {
        return [
            'task' => ['required', 'string', 'max:2000'],
            'agent' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $hint = filled($arguments['agent'] ?? null) && $this->registry->find($user, $arguments['agent']) !== null ? $arguments['agent'] : null;

        $run = $user->agentRuns()->create([
            'agent' => 'orquestador',
            'task' => $arguments['task'],
            'status' => AgentRun::QUEUED,
            'plan' => ['hint' => $hint, 'timezone' => $context->timezone()],
        ]);

        RunDelegation::dispatch($run->id);

        return new ToolResult(
            output: [
                'status' => 'en marcha',
                'run_id' => $run->id,
                'next_step' => 'Di solo una frase corta, como «Lo preparo», y calla. Cuando llegue la nota con el resultado, di lo que diga, sin añadir datos.',
            ],
            canvas: new Canvas('agents.run', ['run_id' => $run->id, 'task' => $arguments['task']]),
        );
    }
}
