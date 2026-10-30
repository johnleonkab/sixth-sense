<?php

namespace App\Connectors\Mcp;

use App\Models\McpServer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Signing in to an MCP server as the MCP authorization spec defines it:
 * OAuth 2.1 with PKCE. The server says where its authorization server is
 * (protected resource metadata), sixth-sense registers itself as a client
 * when the server allows it (dynamic client registration) or uses the
 * client the person gave, and keeps the tokens fresh.
 *
 * Every address read from metadata goes through PublicUrl, like the server's.
 *
 * @see https://modelcontextprotocol.io/specification/2025-06-18/basic/authorization
 */
class McpOAuth
{
    /** How long a sign-in may take before its state is forgotten. */
    private const STATE_MINUTES = 10;

    /**
     * Whether the server asks to sign in: it refuses an unauthenticated
     * request with 401.
     */
    public function asksToSignIn(McpServer $server): bool
    {
        return $this->probe($server)?->status() === 401;
    }

    /**
     * The address to send the person to, to sign in. Finds the endpoints and
     * registers sixth-sense as a client first when needed.
     *
     * @throws McpError
     */
    public function start(McpServer $server, string $redirectUri): string
    {
        $oauth = $this->endpoints($server);

        if (blank($oauth['client_id'] ?? null)) {
            $oauth = [...$oauth, ...$this->register($oauth, $redirectUri)];
        }

        $server->update(['auth' => 'oauth', 'oauth' => $oauth]);

        $verifier = Str::random(64);
        $state = Str::random(40);

        Cache::put("mcp-oauth:{$state}", ['server' => $server->id, 'user' => $server->user_id, 'verifier' => $verifier, 'redirect' => $redirectUri], now()->addMinutes(self::STATE_MINUTES));

        return $oauth['authorization_endpoint'].(str_contains($oauth['authorization_endpoint'], '?') ? '&' : '?').http_build_query(array_filter([
            'response_type' => 'code',
            'client_id' => $oauth['client_id'],
            'redirect_uri' => $redirectUri,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'state' => $state,
            'scope' => $oauth['scope'] ?? null,
            'resource' => $oauth['resource'],
        ]));
    }

    /**
     * The person came back: exchange the code for tokens.
     *
     * @throws McpError
     */
    public function finish(User $user, string $state, string $code): McpServer
    {
        $pending = Cache::pull("mcp-oauth:{$state}");
        $server = is_array($pending) && $pending['user'] === $user->id ? $user->mcpServers()->find($pending['server']) : null;

        if ($server === null) {
            throw new McpError('El inicio de sesión caducó o no es de esta cuenta. Vuelve a intentarlo.');
        }

        $oauth = $server->oauth ?? [];
        $response = $this->token($oauth, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $pending['redirect'],
            'code_verifier' => $pending['verifier'],
        ]);

        $server->update(['oauth' => [...$oauth, ...$this->tokens($response, $oauth)], 'last_error' => null]);

        return $server;
    }

    /**
     * A token that works now, refreshed when it is about to expire.
     *
     * @throws McpError when the person has to sign in again.
     */
    public function accessToken(McpServer $server, bool $forceRefresh = false): string
    {
        $oauth = $server->oauth ?? [];

        if (blank($oauth['access_token'] ?? null)) {
            throw new McpError('Falta iniciar sesión en este servidor MCP.');
        }

        $expires = isset($oauth['expires_at']) ? CarbonImmutable::parse($oauth['expires_at']) : null;

        if (! $forceRefresh && ($expires === null || $expires->isAfter(now()->addMinute()))) {
            return $oauth['access_token'];
        }

        if (blank($oauth['refresh_token'] ?? null)) {
            $server->update(['oauth' => [...$oauth, 'access_token' => null]]);

            throw new McpError('La sesión con este servidor MCP caducó. Vuelve a iniciar sesión.');
        }

        try {
            $response = $this->token($oauth, ['grant_type' => 'refresh_token', 'refresh_token' => $oauth['refresh_token']]);
        } catch (McpError) {
            $server->update(['oauth' => [...$oauth, 'access_token' => null]]);

            throw new McpError('La sesión con este servidor MCP caducó. Vuelve a iniciar sesión.');
        }

        $server->update(['oauth' => [...$oauth, ...$this->tokens($response, $oauth)]]);

        return (string) $server->oauth['access_token'];
    }

    /**
     * Where to sign in and get tokens: from the server's protected resource
     * metadata and its authorization server's metadata. Servers that predate
     * that metadata are their own authorization server.
     *
     * @return array<string, mixed>
     *
     * @throws McpError
     */
    private function endpoints(McpServer $server): array
    {
        $known = $server->oauth ?? [];
        $resource = $this->resourceMetadata($server);
        $issuer = $resource['authorization_servers'][0] ?? $this->origin($server->url);
        $metadata = $this->authorizationMetadata($issuer);

        foreach (['authorization_endpoint', 'token_endpoint'] as $key) {
            if (! is_string($metadata[$key] ?? null)) {
                throw new McpError('Este servidor MCP no publica cómo iniciar sesión. Conéctalo con un token.');
            }
        }

        $scopes = $resource['scopes_supported'] ?? $metadata['scopes_supported'] ?? [];

        return array_filter([
            ...$known,
            'resource' => is_string($resource['resource'] ?? null) ? $resource['resource'] : $server->url,
            'issuer' => $issuer,
            'authorization_endpoint' => $this->checked($metadata['authorization_endpoint']),
            'token_endpoint' => $this->checked($metadata['token_endpoint']),
            'registration_endpoint' => is_string($metadata['registration_endpoint'] ?? null) ? $this->checked($metadata['registration_endpoint']) : null,
            'scope' => $known['scope'] ?? (is_array($scopes) && $scopes !== [] ? implode(' ', $scopes) : null),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function resourceMetadata(McpServer $server): array
    {
        $candidates = [];
        $challenge = (string) $this->probe($server)?->header('WWW-Authenticate');

        if (preg_match('/resource_metadata="([^"]+)"/', $challenge, $match) === 1) {
            $candidates[] = $match[1];
        }

        $path = rtrim((string) parse_url($server->url, PHP_URL_PATH), '/');
        $candidates[] = $this->origin($server->url).'/.well-known/oauth-protected-resource'.$path;
        $candidates[] = $this->origin($server->url).'/.well-known/oauth-protected-resource';

        foreach (array_unique($candidates) as $url) {
            $document = $this->json($url);

            if (is_array($document['authorization_servers'] ?? null) && $document['authorization_servers'] !== []) {
                return $document;
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function authorizationMetadata(string $issuer): array
    {
        $origin = $this->origin($issuer);
        $path = rtrim((string) parse_url($issuer, PHP_URL_PATH), '/');

        foreach (array_unique([
            "{$origin}/.well-known/oauth-authorization-server{$path}",
            "{$origin}/.well-known/openid-configuration{$path}",
            rtrim($issuer, '/').'/.well-known/openid-configuration',
            "{$origin}/.well-known/oauth-authorization-server",
        ]) as $url) {
            $document = $this->json($url);

            if (is_string($document['authorization_endpoint'] ?? null)) {
                return $document;
            }
        }

        // Before the metadata existed, a server was its own authorization
        // server at these paths.
        return [
            'authorization_endpoint' => "{$origin}/authorize",
            'token_endpoint' => "{$origin}/token",
            'registration_endpoint' => "{$origin}/register",
        ];
    }

    /**
     * Register sixth-sense as a public client of the authorization server.
     *
     * @param  array<string, mixed>  $oauth
     * @return array{client_id: string, client_secret?: string}
     *
     * @throws McpError
     */
    private function register(array $oauth, string $redirectUri): array
    {
        if (blank($oauth['registration_endpoint'] ?? null)) {
            throw new McpError('Este servidor no permite registrarse solo: pon un Client ID en «Avanzado».');
        }

        try {
            $response = Http::acceptJson()->timeout(15)->withoutRedirecting()->post($oauth['registration_endpoint'], array_filter([
                'client_name' => 'sixth-sense',
                'redirect_uris' => [$redirectUri],
                'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'],
                'token_endpoint_auth_method' => 'none',
                'scope' => $oauth['scope'] ?? null,
            ]));
        } catch (ConnectionException) {
            throw new McpError('No he podido registrar sixth-sense en este servidor MCP.');
        }

        if ($response->failed() || ! is_string($response->json('client_id'))) {
            throw new McpError('Este servidor no ha dejado registrar sixth-sense (HTTP '.$response->status().'). Pon un Client ID en «Avanzado».');
        }

        return array_filter([
            'client_id' => $response->json('client_id'),
            'client_secret' => $response->json('client_secret'),
        ], fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $oauth
     * @param  array<string, string>  $grant
     *
     * @throws McpError
     */
    private function token(array $oauth, array $grant): Response
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(15)->withoutRedirecting()->post($this->checked((string) ($oauth['token_endpoint'] ?? '')), array_filter([
                ...$grant,
                'client_id' => $oauth['client_id'] ?? null,
                'client_secret' => $oauth['client_secret'] ?? null,
                'resource' => $oauth['resource'] ?? null,
            ]));
        } catch (ConnectionException) {
            throw new McpError('No he podido conectar con el servidor de inicio de sesión.');
        }

        if ($response->failed() || ! is_string($response->json('access_token'))) {
            throw new McpError('El inicio de sesión no se completó: '.($response->json('error_description') ?? $response->json('error') ?? "HTTP {$response->status()}"));
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $oauth
     * @return array{access_token: string, refresh_token: ?string, expires_at: ?string}
     */
    private function tokens(Response $response, array $oauth): array
    {
        $seconds = $response->json('expires_in');

        return [
            'access_token' => (string) $response->json('access_token'),
            // A server may keep the same refresh token instead of rotating it.
            'refresh_token' => $response->json('refresh_token') ?? ($oauth['refresh_token'] ?? null),
            'expires_at' => is_numeric($seconds) ? now()->addSeconds((int) $seconds)->toIso8601String() : null,
        ];
    }

    /**
     * An unauthenticated initialize, to see whether the server asks to sign in.
     */
    private function probe(McpServer $server): ?Response
    {
        if (PublicUrl::problem($server->url) !== null) {
            return null;
        }

        try {
            return Http::acceptJson()->withHeaders(['Accept' => 'application/json, text/event-stream'])->timeout(15)->withoutRedirecting()->post($server->url, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => ['protocolVersion' => McpClient::PROTOCOL_VERSION, 'capabilities' => (object) [], 'clientInfo' => ['name' => 'sixth-sense', 'version' => '1.0']],
            ]);
        } catch (ConnectionException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $url): array
    {
        if (PublicUrl::problem($url) !== null) {
            return [];
        }

        try {
            $response = Http::acceptJson()->timeout(10)->withoutRedirecting()->get($url);
        } catch (ConnectionException) {
            return [];
        }

        return $response->successful() && is_array($response->json()) ? $response->json() : [];
    }

    /**
     * @throws McpError
     */
    private function checked(string $url): string
    {
        if (($problem = PublicUrl::problem($url)) !== null) {
            throw new McpError("El servidor MCP indica una dirección que no se puede usar: {$problem}");
        }

        return $url;
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ":{$parts['port']}" : '');
    }
}
