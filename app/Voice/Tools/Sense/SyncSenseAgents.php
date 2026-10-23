<?php

namespace App\Voice\Tools\Sense;

use App\Models\User;
use App\Sense\SyncAgents;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Copies agents from the work repository into sixth-sense. The voice uses
 * the copy, so a new agent is invisible until this runs.
 */
class SyncSenseAgents implements VoiceTool
{
    public function __construct(private SyncAgents $agents) {}

    public function name(): string
    {
        return 'sync_sense_agents';
    }

    public function description(): string
    {
        return 'Copia los agentes del archivo de trabajo (agents/*/agent.md, salvo _template) al runtime de sixth-sense. '
            .'Llámalo después de crear o cambiar un agente, para que el orquestador pueda usarlo. No escribe en GitHub.';
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
        if ($user->workRepository() === null) {
            return ToolResult::error('No hay un archivo de trabajo. Se elige en Configuración, Conexiones.');
        }

        $count = $this->agents->sync($user);
        $names = $user->senseAgents()->orderBy('name')->pluck('name', 'slug')->all();

        return new ToolResult([
            'agents' => $count,
            'available' => $names,
        ]);
    }
}
