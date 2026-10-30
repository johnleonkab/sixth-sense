<?php

namespace App\Actions\Handlers;

use App\Actions\ActionHandler;
use App\Connectors\Mcp\McpServers;
use App\Models\PendingAction;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Calls a tool of an MCP server that may change something there, once the
 * person said yes.
 */
class CallMcpTool implements ActionHandler
{
    public function __construct(private McpServers $servers) {}

    public function execute(PendingAction $action): array
    {
        $server = $action->user->mcpServers()->where('enabled', true)->find($action->payload['server_id'] ?? 0);

        if ($server === null) {
            throw new RuntimeException('Ese servidor MCP ya no está conectado.');
        }

        $result = $this->servers->call($server, (string) $action->payload['tool'], (array) ($action->payload['arguments'] ?? []));

        if ($result['is_error']) {
            throw new RuntimeException('El servidor respondió con un error: '.Str::limit($result['text'], 300));
        }

        return array_filter(['result' => Str::limit($result['text'], 500)]);
    }
}
