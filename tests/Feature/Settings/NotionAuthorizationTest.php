<?php

use App\Connections\Capability;
use App\Connections\Provider;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'app.url' => 'http://localhost:8000',
        'services.notion.client_id' => 'notion-client',
        'services.notion.client_secret' => 'notion-secret',
        'services.notion.redirect' => 'https://tunnel.example.com/settings/connections/notion/callback',
    ]);
    $this->user = User::factory()->create();
});

function notionRedirect(): array
{
    $location = test()->actingAs(test()->user)->get(route('settings.connections.redirect', 'notion'))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return [$location, $query];
}

test('users are sent to notion to pick the pages it may use', function () {
    [$location, $query] = notionRedirect();

    expect($location)->toStartWith('https://api.notion.com/v1/oauth/authorize')
        ->and($query)->toMatchArray(['client_id' => 'notion-client', 'response_type' => 'code', 'owner' => 'user', 'redirect_uri' => 'https://tunnel.example.com/settings/connections/notion/callback']);
});

test('the callback stores the workspace account, with every capability, even without the browser session', function () {
    [, $query] = notionRedirect();
    Http::fake(['api.notion.com/v1/oauth/token' => Http::response([
        'access_token' => 'ntn_1', 'refresh_token' => 'nrt_1', 'bot_id' => 'B1', 'workspace_id' => 'W1', 'workspace_name' => 'CoverManager',
        'owner' => ['type' => 'user', 'user' => ['id' => 'U1', 'name' => 'John León', 'avatar_url' => null, 'person' => ['email' => 'jhon@cm.com']]],
    ])]);
    auth()->logout();

    $this->get(route('settings.connections.callback', ['provider' => 'notion', 'code' => 'abc', 'state' => $query['state']]))
        ->assertRedirect('http://localhost:8000/settings/connections');

    $account = ConnectedAccount::sole();

    expect($account->provider)->toBe(Provider::Notion)
        ->and($account->external_account_id)->toBe('W1:U1')
        ->and($account->email)->toBe('jhon@cm.com')
        ->and($account->refresh_token)->toBe('nrt_1')
        ->and($account->metadata)->toBe(['workspace_id' => 'W1', 'workspace_name' => 'CoverManager', 'bot_id' => 'B1', 'user_id' => 'U1'])
        ->and($account->can(Capability::NotionEdit))->toBeTrue()
        ->and($account->can(Capability::NotionComment))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request['grant_type'] === 'authorization_code' && $request['code'] === 'abc'
        && $request->header('Authorization')[0] === 'Basic '.base64_encode('notion-client:notion-secret'));
});

test('cancelling in notion connects nothing', function () {
    [, $query] = notionRedirect();

    $this->get(route('settings.connections.callback', ['provider' => 'notion', 'error' => 'access_denied', 'state' => $query['state']]))->assertRedirect();

    expect(ConnectedAccount::count())->toBe(0);
});
