<?php

use App\Models\McpServer;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
});

/**
 * An MCP server that asks to sign in, its metadata, an authorization server
 * that lets clients register, and the tokens it hands out.
 *
 * @param  array<string, mixed>  $authorization  Overrides of the authorization server's metadata.
 */
function fakeSignInServer(array $authorization = [], string $authorizationServer = 'https://8.8.8.8'): void
{
    Http::fake(function (Request $request) use ($authorization, $authorizationServer) {
        $url = $request->url();

        if ($url === 'https://1.1.1.1/mcp') {
            $message = json_decode($request->body(), true);

            if (! $request->hasHeader('Authorization')) {
                return Http::response('', 401, ['WWW-Authenticate' => 'Bearer resource_metadata="https://1.1.1.1/.well-known/oauth-protected-resource/mcp"']);
            }

            if (! in_array($request->header('Authorization')[0], ['Bearer at-1', 'Bearer at-2'], true)) {
                return Http::response('', 401);
            }

            return match ($message['method']) {
                'initialize' => Http::response(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ['protocolVersion' => '2025-06-18']]),
                'notifications/initialized' => Http::response('', 202),
                'tools/list' => Http::response(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ['tools' => [['name' => 'list_issues', 'inputSchema' => ['type' => 'object'], 'annotations' => ['readOnlyHint' => true]]]]]),
            };
        }

        return match ($url) {
            'https://1.1.1.1/.well-known/oauth-protected-resource/mcp' => Http::response(['resource' => 'https://1.1.1.1/mcp', 'authorization_servers' => [$authorizationServer], 'scopes_supported' => ['read']]),
            "{$authorizationServer}/.well-known/oauth-authorization-server" => Http::response([
                'issuer' => $authorizationServer,
                'authorization_endpoint' => "{$authorizationServer}/authorize",
                'token_endpoint' => "{$authorizationServer}/token",
                'registration_endpoint' => "{$authorizationServer}/register",
                ...$authorization,
            ]),
            "{$authorizationServer}/register" => Http::response(['client_id' => 'client-123'], 201),
            "{$authorizationServer}/token" => $request['grant_type'] === 'refresh_token'
                ? Http::response(['access_token' => 'at-2', 'expires_in' => 3600])
                : Http::response(['access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3600]),
            default => Http::response('', 404),
        };
    });
}

/**
 * Add the server and follow the sign-in until the authorization page.
 *
 * @return array{server: McpServer, location: string, state: string}
 */
function startSignIn(): array
{
    test()->actingAs(test()->user)->post(route('settings.mcp.store'), ['name' => 'Linear', 'url' => 'https://1.1.1.1/mcp'])->assertRedirect();

    $server = test()->user->mcpServers()->sole();
    $location = test()->get(route('settings.mcp.connect', $server))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return ['server' => $server, 'location' => $location, 'state' => $query['state']];
}

test('a server that asks to sign in is connected without a token: it registers, signs in with PKCE and lists its tools', function () {
    fakeSignInServer();
    ['server' => $server, 'location' => $location, 'state' => $state] = startSignIn();

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://8.8.8.8/authorize?')
        ->and($query)->toMatchArray(['client_id' => 'client-123', 'code_challenge_method' => 'S256', 'resource' => 'https://1.1.1.1/mcp', 'scope' => 'read', 'redirect_uri' => route('settings.mcp.callback')])
        ->and($server->fresh()->auth)->toBe('oauth')
        ->and($server->fresh()->signedIn())->toBeFalse();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://8.8.8.8/register' && $request['token_endpoint_auth_method'] === 'none' && $request['redirect_uris'] === [route('settings.mcp.callback')]);

    $this->get(route('settings.mcp.callback', ['code' => 'the-code', 'state' => $state]))->assertRedirect(route('settings.mcp.index'));

    $server->refresh();

    expect($server->signedIn())->toBeTrue()
        ->and($server->last_error)->toBeNull()
        ->and(array_column($server->tools, 'name'))->toBe(['list_issues'])
        ->and($server->getRawOriginal('oauth'))->not->toContain('at-1');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://8.8.8.8/token'
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'the-code'
        && strlen($request['code_verifier']) === 64
        && $request['resource'] === 'https://1.1.1.1/mcp');
});

test('an expired session is refreshed on its own', function () {
    fakeSignInServer();
    ['server' => $server, 'state' => $state] = startSignIn();
    $this->get(route('settings.mcp.callback', ['code' => 'the-code', 'state' => $state]));

    $server->refresh();
    $server->update(['oauth' => [...$server->oauth, 'expires_at' => now()->subMinute()->toIso8601String()]]);

    $this->post(route('settings.mcp.refresh', $server))->assertRedirect();

    expect($server->fresh()->oauth['access_token'])->toBe('at-2')
        ->and($server->fresh()->oauth['refresh_token'])->toBe('rt-1');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://8.8.8.8/token' && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'rt-1');
});

test('a sign-in that did not start here is refused', function () {
    fakeSignInServer();
    startSignIn();

    $this->get(route('settings.mcp.callback', ['code' => 'the-code', 'state' => 'forged']))
        ->assertRedirect(route('settings.mcp.index'))
        ->assertInertiaFlash('toast.type', 'warning');

    expect($this->user->mcpServers()->sole()->signedIn())->toBeFalse();
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/token'));
});

test('without dynamic registration it asks for a client id, and uses the one given', function () {
    fakeSignInServer(['registration_endpoint' => null]);

    $this->actingAs($this->user)->post(route('settings.mcp.store'), ['name' => 'Linear', 'url' => 'https://1.1.1.1/mcp']);
    $server = $this->user->mcpServers()->sole();

    $this->get(route('settings.mcp.connect', $server))->assertRedirect(route('settings.mcp.index'));
    expect($server->fresh()->last_error)->toContain('Client ID');

    $server->delete();
    $this->post(route('settings.mcp.store'), ['name' => 'Linear', 'url' => 'https://1.1.1.1/mcp', 'client_id' => 'mine']);
    $location = $this->get(route('settings.mcp.connect', $this->user->mcpServers()->sole()))->headers->get('Location');

    expect($location)->toContain('client_id=mine');
});

test('an authorization server on a private address is never called', function () {
    fakeSignInServer(authorizationServer: 'https://10.0.0.1');

    $this->actingAs($this->user)->post(route('settings.mcp.store'), ['name' => 'Interno', 'url' => 'https://1.1.1.1/mcp']);
    $server = $this->user->mcpServers()->sole();

    $this->get(route('settings.mcp.connect', $server))->assertRedirect(route('settings.mcp.index'));

    expect($server->fresh()->last_error)->toContain('no se puede usar');
    Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://10.0.0.1'));
});
