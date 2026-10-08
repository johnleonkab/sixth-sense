<?php

use App\Connections\Capability;
use App\Connections\Provider;
use App\Jobs\WarmChats;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'app.url' => 'http://localhost:8000',
        'services.slack.client_id' => '123.456',
        'services.slack.client_secret' => 'secret',
        'services.slack.redirect' => 'https://tunnel.example.com/settings/connections/slack/callback',
    ]);
    $this->user = User::factory()->create();
    Queue::fake();
});

function slackRedirect(): array
{
    $location = test()->actingAs(test()->user)->get(route('settings.connections.redirect', 'slack'))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return [$location, $query];
}

function fakeSlackOAuth(string $scopes): void
{
    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response(['ok' => true, 'team' => ['id' => 'T1', 'name' => 'CoverManager'], 'authed_user' => ['id' => 'U1', 'access_token' => 'xoxp-1', 'scope' => $scopes]]),
        'slack.com/api/users.info*' => Http::response(['ok' => true, 'user' => ['id' => 'U1', 'name' => 'jhon', 'profile' => ['real_name' => 'John León', 'email' => 'jhon@covermanager.com', 'image_72' => 'https://avatars/jhon.png']]]),
    ]);
}

test('users are sent to slack asking for a user token with every scope', function () {
    [$location, $query] = slackRedirect();

    expect($location)->toStartWith('https://slack.com/oauth/v2/authorize')
        ->and($query['client_id'])->toBe('123.456')
        ->and($query['redirect_uri'])->toBe('https://tunnel.example.com/settings/connections/slack/callback')
        ->and(explode(',', $query['user_scope']))->toEqualCanonicalizing(Provider::Slack->scopes())
        ->and($query)->not->toHaveKey('scope');
});

test('the callback stores the workspace account even without the browser session', function () {
    [, $query] = slackRedirect();
    fakeSlackOAuth(implode(',', Provider::Slack->scopes()));
    auth()->logout();

    $this->get(route('settings.connections.callback', ['provider' => 'slack', 'code' => 'abc', 'state' => $query['state']]))
        ->assertRedirect('http://localhost:8000/settings/connections');

    $account = ConnectedAccount::sole();

    expect($account->user->is($this->user))->toBeTrue()
        ->and($account->provider)->toBe(Provider::Slack)
        ->and($account->external_account_id)->toBe('T1:U1')
        ->and($account->email)->toBe('jhon@covermanager.com')
        ->and($account->access_token)->toBe('xoxp-1')
        ->and($account->expires_at)->toBeNull()
        ->and($account->metadata)->toBe(['team_id' => 'T1', 'team_name' => 'CoverManager', 'user_id' => 'U1'])
        ->and($account->can(Capability::SlackSend))->toBeTrue();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'oauth.v2.access') && $request['code'] === 'abc' && $request['client_secret'] === 'secret');
    Queue::assertPushed(WarmChats::class, fn (WarmChats $job) => $job->user->is($this->user));
});

test('a state can only be used once and can not be forged', function () {
    [, $query] = slackRedirect();
    fakeSlackOAuth('channels:read');

    $this->actingAs($this->user)->get(route('settings.connections.callback', ['provider' => 'slack', 'code' => 'abc', 'state' => $query['state']]));
    $this->actingAs($this->user)->get(route('settings.connections.callback', ['provider' => 'slack', 'code' => 'abc', 'state' => $query['state']]))
        ->assertInertiaFlash('toast.type', 'error');
    $this->actingAs($this->user)->get(route('settings.connections.callback', ['provider' => 'slack', 'code' => 'abc', 'state' => 'forged']))
        ->assertInertiaFlash('toast.type', 'error');

    expect(ConnectedAccount::count())->toBe(1);
});

test('missing scopes are reported and cancelling stores nothing', function () {
    [, $query] = slackRedirect();
    fakeSlackOAuth('channels:read,channels:history');

    $this->actingAs($this->user)
        ->get(route('settings.connections.callback', ['provider' => 'slack', 'code' => 'abc', 'state' => $query['state']]))
        ->assertInertiaFlash('toast.type', 'warning');

    $this->actingAs($this->user)
        ->get(route('settings.connections.callback', ['provider' => 'slack', 'error' => 'access_denied']))
        ->assertInertiaFlash('toast.type', 'warning');

    expect(ConnectedAccount::sole()->can(Capability::SlackRead))->toBeFalse();
});
