<?php

namespace App\Connectors\Mcp;

use App\Models\McpServer;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The person's MCP servers: checking one refreshes the tools it offers, and
 * a call is answered in a form the voice can read.
 */
class McpServers
{
    /** What the voice reads of a result, at most. */
    private const MAX_RESULT = 12000;

    public function __construct(private McpClient $client) {}

    /**
     * Connect, list its tools and remember them, or remember why it failed.
     */
    public function check(McpServer $server): bool
    {
        try {
            $server->update(['tools' => $this->client->tools($server), 'checked_at' => now(), 'last_error' => null]);

            return true;
        } catch (McpError $error) {
            $server->update(['checked_at' => now(), 'last_error' => $error->getMessage()]);

            return false;
        }
    }

    /**
     * An enabled server of the person by its name, ignoring case.
     */
    public function named(User $user, string $name): ?McpServer
    {
        return $user->mcpServers()->where('enabled', true)->get()
            ->first(fn (McpServer $server): bool => Str::lower($server->name) === Str::lower(trim($name)));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{text: string, structured: mixed, is_error: bool}
     *
     * @throws McpError
     */
    public function call(McpServer $server, string $tool, array $arguments): array
    {
        $result = $this->client->call($server, $tool, $arguments);
        $result['text'] = Str::limit($result['text'], self::MAX_RESULT);

        if ($result['structured'] !== null && mb_strlen((string) json_encode($result['structured'])) > self::MAX_RESULT) {
            $result['structured'] = null;
        }

        return $result;
    }
}
