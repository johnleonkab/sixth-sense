<?php

use App\Connections\Capability;
use App\Connections\Provider;
use App\Models\ConnectedAccount;
use App\Models\GithubRepository;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'app.url' => 'http://localhost:8000',
        'services.github.client_id' => 'github-client',
        'services.github.client_secret' => 'github-secret',
        'services.github.redirect' => 'https://tunnel.example.com/settings/connections/github/callback',
    ]);
    $this->user = User::factory()->create();
});

function githubRedirect(): array
{
    $location = test()->actingAs(test()->user)->get(route('settings.connections.redirect', 'github'))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return [$location, $query];
}

function fakeGithubOAuth(string $scope = 'repo,read:user,user:email'): void
{
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_1', 'scope' => $scope, 'token_type' => 'bearer']),
        'api.github.com/user/repos*' => Http::response([
            ['id' => 7, 'name' => 'sixth-sense', 'full_name' => 'jhon/sixth-sense', 'private' => true, 'default_branch' => 'main', 'owner' => ['login' => 'jhon'], 'permissions' => ['push' => true]],
            ['id' => 8, 'name' => 'notes', 'full_name' => 'jhon/notes', 'private' => false, 'default_branch' => 'main', 'owner' => ['login' => 'jhon'], 'permissions' => ['push' => false]],
        ]),
        'api.github.com/user' => Http::response(['id' => 42, 'login' => 'jhon', 'name' => 'John León', 'email' => 'jhon@covermanager.com', 'avatar_url' => 'https://avatars/jhon.png']),
    ]);
}

test('users are sent to github asking for every repository and their identity', function () {
    [$location, $query] = githubRedirect();

    expect($location)->toStartWith('https://github.com/login/oauth/authorize')
        ->and($query['client_id'])->toBe('github-client')
        ->and($query['redirect_uri'])->toBe('https://tunnel.example.com/settings/connections/github/callback')
        ->and(explode(' ', $query['scope']))->toEqualCanonicalizing([...Provider::GitHub->scopes(), 'read:user', 'user:email']);
});

test('the callback stores the account and the repositories it can see', function () {
    [, $query] = githubRedirect();
    fakeGithubOAuth();
    auth()->logout();

    $this->get(route('settings.connections.callback', ['provider' => 'github', 'code' => 'abc', 'state' => $query['state']]))
        ->assertRedirect('http://localhost:8000/settings/connections');

    $account = ConnectedAccount::sole();

    expect($account->user->is($this->user))->toBeTrue()
        ->and($account->provider)->toBe(Provider::GitHub)
        ->and($account->external_account_id)->toBe('42')
        ->and($account->email)->toBe('jhon@covermanager.com')
        ->and($account->access_token)->toBe('gho_1')
        ->and($account->refresh_token)->toBeNull()
        ->and($account->expires_at)->toBeNull()
        ->and($account->metadata)->toBe(['login' => 'jhon'])
        ->and($account->can(Capability::GithubRead))->toBeTrue()
        ->and($account->can(Capability::GithubWrite))->toBeTrue()
        ->and($account->githubRepositories()->pluck('full_name')->all())->toBe(['jhon/notes', 'jhon/sixth-sense'])
        ->and($account->githubRepositories()->where('full_name', 'jhon/notes')->first()->can_push)->toBeFalse()
        ->and($account->githubRepositories()->where('included', true)->exists())->toBeFalse();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'access_token') && $request['code'] === 'abc' && $request['client_secret'] === 'github-secret');
});

test('an expiring grant keeps the refresh token, encrypted, and when it runs out', function () {
    [, $query] = githubRedirect();
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response([
            'access_token' => 'gho_1',
            'refresh_token' => 'ghr_1',
            'expires_in' => 28800,
            'scope' => 'repo,read:user,user:email',
            'token_type' => 'bearer',
        ]),
        'api.github.com/user/repos*' => Http::response([]),
        'api.github.com/user' => Http::response(['id' => 42, 'login' => 'jhon', 'name' => 'John León', 'email' => 'jhon@covermanager.com']),
    ]);

    $this->get(route('settings.connections.callback', ['provider' => 'github', 'code' => 'abc', 'state' => $query['state']]));

    $account = ConnectedAccount::sole();
    $stored = DB::table('connected_accounts')->where('id', $account->id)->value('refresh_token');

    expect($account->refresh_token)->toBe('ghr_1')
        ->and($account->expires_at->diffInSeconds(now()->addSeconds(28799)))->toBeLessThan(5)
        ->and($stored)->not->toContain('ghr_1');
});

test('connecting again keeps the repositories the person already chose', function () {
    [, $query] = githubRedirect();
    fakeGithubOAuth();

    $this->get(route('settings.connections.callback', ['provider' => 'github', 'code' => 'abc', 'state' => $query['state']]));
    $repository = GithubRepository::where('full_name', 'jhon/sixth-sense')->first();
    $repository->update(['included' => true, 'writes_code' => true]);

    [, $again] = githubRedirect();
    $this->get(route('settings.connections.callback', ['provider' => 'github', 'code' => 'def', 'state' => $again['state']]));

    expect(ConnectedAccount::count())->toBe(1)
        ->and($repository->fresh()->included)->toBeTrue()
        ->and($repository->fresh()->writes_code)->toBeTrue();
});

test('declining connects nothing and a state can only be used once', function () {
    [, $query] = githubRedirect();
    fakeGithubOAuth();

    $this->get(route('settings.connections.callback', ['provider' => 'github', 'error' => 'access_denied']))
        ->assertInertiaFlash('toast.type', 'warning');

    $this->get(route('settings.connections.callback', ['provider' => 'github', 'code' => 'abc', 'state' => $query['state']]));
    $this->get(route('settings.connections.callback', ['provider' => 'github', 'code' => 'abc', 'state' => $query['state']]))
        ->assertInertiaFlash('toast.type', 'error');

    expect(ConnectedAccount::count())->toBe(1);
});
