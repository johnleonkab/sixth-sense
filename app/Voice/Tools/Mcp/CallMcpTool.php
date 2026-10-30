<?php

namespace App\Voice\Tools\Mcp;

use App\Actions\Actions;
use App\Connectors\Mcp\McpError;
use App\Connectors\Mcp\McpServers;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Calls a tool of one of the person's MCP servers. A tool the server marks
 * as read-only runs now; any other may change something there, so it is
 * proposed and waits for the person's "sí".
 */
class CallMcpTool implements VoiceTool
{
    public function __construct(
        private McpServers $servers,
        private Actions $actions,
    ) {}

    public function name(): string
    {
        return 'mcp_call';
    }

    public function description(): string
    {
        return 'Llama a una herramienta de un servidor MCP conectado (mira antes mcp_tools). Si solo lee, devuelve el resultado: para enseñarlo, usa show_view. '
            .'Si puede cambiar algo, queda como propuesta en pantalla y espera el sí del usuario.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'server' => ['type' => 'string', 'description' => 'Nombre del servidor MCP.'],
                'tool' => ['type' => 'string', 'description' => 'Nombre de la herramienta.'],
                'arguments' => ['type' => 'object', 'description' => 'Argumentos, según los parámetros de la herramienta.'],
            ],
            'required' => ['server', 'tool'],
        ];
    }

    public function rules(): array
    {
        return [
            'server' => ['required', 'string', 'max:60'],
            'tool' => ['required', 'string', 'max:200'],
            'arguments' => ['nullable', 'array'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $server = $this->servers->named($user, $arguments['server']);

        if ($server === null) {
            return ToolResult::error("No hay un servidor MCP «{$arguments['server']}» activo.");
        }

        $tool = $server->tool($arguments['tool']);

        if ($tool === null) {
            return ToolResult::error("«{$server->name}» no tiene la herramienta «{$arguments['tool']}». Mira mcp_tools.");
        }

        $parameters = $arguments['arguments'] ?? [];

        if (! $tool['read_only']) {
            $action = $this->actions->propose($user, 'mcp.call', ($tool['title'] ?? $tool['name'])." en {$server->name}", [
                'server_id' => $server->id,
                'tool' => $tool['name'],
                'arguments' => $parameters,
            ]);

            return new ToolResult(
                output: [
                    'pending_action_id' => $action->id,
                    'status' => 'esperando confirmación',
                    'shown_on_screen' => true,
                    'next_step' => 'Es solo una propuesta: di qué hará y pregunta si lo hace. Tú no puedes confirmarlo. '
                        .'Cuando responda, consulta get_action_status y cuenta exactamente lo que diga.',
                ],
                canvas: new Canvas('mcp.call', [
                    'action' => $action->toState(),
                    'server' => $server->name,
                    'tool' => $tool['title'] ?? $tool['name'],
                    'description' => $tool['description'],
                    'arguments' => $parameters,
                ]),
            );
        }

        try {
            $result = $this->servers->call($server, $tool['name'], $parameters);
        } catch (McpError $error) {
            return ToolResult::error($error->getMessage());
        }

        if ($result['is_error']) {
            return ToolResult::error("«{$tool['name']}» respondió con un error: {$result['text']}");
        }

        return new ToolResult([
            'server' => $server->name,
            'tool' => $tool['name'],
            'result' => $result['text'],
            'structured' => $result['structured'],
            'next_step' => 'Si hay datos que mirar, enséñalos con show_view; en voz, solo lo esencial.',
        ]);
    }
}
