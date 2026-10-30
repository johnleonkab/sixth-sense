<?php

namespace App\Connectors\Mcp;

use App\Models\McpServer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A small MCP client over Streamable HTTP: it opens a session, lists the
 * server's tools and calls one. A server may answer each request as JSON or
 * as a stream of events; both are read.
 *
 * @see https://modelcontextprotocol.io/specification/2025-06-18/basic/transports
 */
class McpClient
{
    public const PROTOCOL_VERSION = '2025-06-18';

    /** Pages of tools read at most. */
    private const MAX_PAGES = 5;

    private int $id = 0;

    public function __construct(private McpOAuth $oauth) {}

    /**
     * Every tool the server offers, as sixth-sense keeps them.
     *
     * @return list<array{name: string, title: ?string, description: string, input_schema: array<string, mixed>, read_only: bool}>
     *
     * @throws McpError
     */
    public function tools(McpServer $server): array
    {
        $session = $this->open($server);
        $tools = [];
        $cursor = null;
        $pages = 0;

        do {
            $result = $this->request($server, $session, 'tools/list', $cursor === null ? [] : ['cursor' => $cursor]);

            foreach ($result['tools'] ?? [] as $tool) {
                if (! is_array($tool) || ! is_string($tool['name'] ?? null)) {
                    continue;
                }

                $tools[] = [
                    'name' => $tool['name'],
                    'title' => is_string($tool['title'] ?? $tool['annotations']['title'] ?? null) ? ($tool['title'] ?? $tool['annotations']['title']) : null,
                    'description' => Str::limit((string) ($tool['description'] ?? ''), 1000),
                    'input_schema' => is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : ['type' => 'object'],
                    'read_only' => ($tool['annotations']['readOnlyHint'] ?? false) === true,
                ];
            }

            $cursor = is_string($result['nextCursor'] ?? null) ? $result['nextCursor'] : null;
        } while ($cursor !== null && ++$pages < self::MAX_PAGES);

        return $tools;
    }

    /**
     * Call one tool. The result keeps the text the server returned and its
     * structured content, if any.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{text: string, structured: mixed, is_error: bool}
     *
     * @throws McpError
     */
    public function call(McpServer $server, string $tool, array $arguments): array
    {
        $session = $this->open($server);
        $result = $this->request($server, $session, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
        $texts = [];

        foreach ($result['content'] ?? [] as $part) {
            $texts[] = match ($part['type'] ?? null) {
                'text' => (string) ($part['text'] ?? ''),
                'resource' => (string) ($part['resource']['text'] ?? $part['resource']['uri'] ?? ''),
                'resource_link' => (string) ($part['uri'] ?? ''),
                'image' => '[imagen]',
                default => '',
            };
        }

        return [
            'text' => trim(implode("\n", array_filter($texts))),
            'structured' => $result['structuredContent'] ?? null,
            'is_error' => (bool) ($result['isError'] ?? false),
        ];
    }

    /**
     * Initialize a session: the server may give it an id, and every later
     * request carries it.
     *
     * @return array{id: ?string, version: string}
     *
     * @throws McpError
     */
    private function open(McpServer $server): array
    {
        if (($problem = PublicUrl::problem($server->url)) !== null) {
            throw new McpError($problem);
        }

        [$result, $response] = $this->send($server, null, [
            'jsonrpc' => '2.0',
            'id' => ++$this->id,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'sixth-sense', 'version' => '1.0'],
            ],
        ]);

        $session = [
            'id' => $response->header('Mcp-Session-Id') ?: null,
            'version' => is_string($result['protocolVersion'] ?? null) ? $result['protocolVersion'] : self::PROTOCOL_VERSION,
        ];

        $this->post($server, $session, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        return $session;
    }

    /**
     * @param  array{id: ?string, version: string}  $session
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws McpError
     */
    private function request(McpServer $server, array $session, string $method, array $params): array
    {
        return $this->send($server, $session, [
            'jsonrpc' => '2.0',
            'id' => ++$this->id,
            'method' => $method,
            'params' => (object) $params,
        ])[0];
    }

    /**
     * @param  array{id: ?string, version: string}|null  $session
     * @param  array<string, mixed>  $message
     * @return array{0: array<string, mixed>, 1: Response}
     *
     * @throws McpError
     */
    private function send(McpServer $server, ?array $session, array $message): array
    {
        $response = $this->post($server, $session, $message);
        $answer = $this->answer($response, $message['id']);

        if (isset($answer['error'])) {
            throw new McpError('El servidor MCP respondió con un error: '.Str::limit((string) ($answer['error']['message'] ?? 'desconocido'), 200));
        }

        if (! is_array($answer['result'] ?? null)) {
            throw new McpError('El servidor MCP no respondió como se esperaba.');
        }

        return [$answer['result'], $response];
    }

    /**
     * @param  array{id: ?string, version: string}|null  $session
     * @param  array<string, mixed>  $message
     *
     * @throws McpError
     */
    private function post(McpServer $server, ?array $session, array $message, bool $retried = false): Response
    {
        $headers = array_filter([
            'Accept' => 'application/json, text/event-stream',
            'Mcp-Session-Id' => $session['id'] ?? null,
            'MCP-Protocol-Version' => $session['version'] ?? null,
        ]);

        $token = $server->usesOAuth() ? $this->oauth->accessToken($server, $retried) : $server->token;

        try {
            $request = Http::withHeaders($headers)->timeout(25)->withoutRedirecting();

            if (filled($token)) {
                $request = $request->withToken($token);
            }

            $response = $request->post($server->url, $message);
        } catch (ConnectionException) {
            throw new McpError('No he podido conectar con el servidor MCP.');
        }

        // A token that expired early: refresh it once and try again.
        if ($response->status() === 401 && $server->usesOAuth() && ! $retried) {
            return $this->post($server, $session, $message, true);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new McpError($server->usesOAuth() ? 'El servidor MCP no acepta la sesión. Vuelve a iniciar sesión.' : 'El servidor MCP no acepta el token: revisa que sea válido.');
        }

        if ($response->failed()) {
            throw new McpError("El servidor MCP respondió con HTTP {$response->status()}.");
        }

        return $response;
    }

    /**
     * The JSON-RPC answer to a request: the body itself, or the event with
     * the same id in a stream.
     *
     * @return array<string, mixed>
     */
    private function answer(Response $response, int $id): array
    {
        if (str_contains((string) $response->header('Content-Type'), 'text/event-stream')) {
            foreach (preg_split('/\R/', $response->body()) ?: [] as $line) {
                if (! str_starts_with($line, 'data:')) {
                    continue;
                }

                $event = json_decode(trim(substr($line, 5)), true);

                if (is_array($event) && ($event['id'] ?? null) === $id) {
                    return $event;
                }
            }

            return [];
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }
}
