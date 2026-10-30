<?php

namespace App\Voice\Tools\Mcp;

use App\Models\McpServer;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use Illuminate\Support\Str;

/**
 * What the person's MCP servers offer, from the last check: no server is
 * called to answer this.
 */
class ListMcpTools implements VoiceTool
{
    public function name(): string
    {
        return 'mcp_tools';
    }

    public function description(): string
    {
        return 'Lista las herramientas de los servidores MCP que el usuario ha conectado, con sus parámetros y si solo leen. '
            .'Úsala antes de mcp_call para saber qué herramienta y qué argumentos usar.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'server' => ['type' => 'string', 'description' => 'Solo este servidor, por su nombre. Vacío, todos.'],
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'server' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $servers = $user->mcpServers()->where('enabled', true)->orderBy('name')->get()
            ->when(filled($arguments['server'] ?? null), fn ($servers) => $servers->filter(fn (McpServer $server): bool => Str::lower($server->name) === Str::lower(trim($arguments['server']))));

        if ($servers->isEmpty()) {
            return ToolResult::error('No hay servidores MCP conectados con ese nombre. Se añaden en Configuración, MCP.');
        }

        return new ToolResult([
            'servers' => $servers->map(fn (McpServer $server): array => [
                'server' => $server->name,
                'error' => $server->last_error,
                'tools' => array_map(fn (array $tool): array => [
                    'name' => $tool['name'],
                    'description' => Str::limit($tool['description'], 300),
                    'read_only' => $tool['read_only'],
                    'parameters' => $tool['input_schema']['properties'] ?? [],
                    'required' => $tool['input_schema']['required'] ?? [],
                ], $server->tools ?? []),
            ])->values()->all(),
        ]);
    }
}
