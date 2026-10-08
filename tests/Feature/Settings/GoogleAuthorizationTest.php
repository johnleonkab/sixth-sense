<?php

use App\Connections\AccountStatus;
use App\Connections\Provider;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as OAuthUser;

/**
 * Make Socialite return this Google account from the callback.
 */
function googleReturns(array $overrides = []): OAuthUser
{
    $user = googleUser($overrides);

    Socialite::shouldReceive('driver->user')->andReturn($user);

    return $user;
}

function googleUser(array $overrides = []): OAuthUser
{
    $attributes = [
        'id' => '1234567890',
        'email' => 'jhon@example.com',
        'name' => 'John León',
        'avatar' => 'https://lh3.googleusercontent.com/a/photo',
        'token' => 'ya29.access',
        'refresh_token' => '1//refresh',
        'expires_in' => 3599,
        'scopes' => ['openid', 'https://www.googleapis.com/auth/userinfo.email', ...Provider::Google->scopes()],
        ...$overrides,
    ];

    $user = (new OAuthUser)
        ->map(['id' => $attributes['id'], 'email' => $attributes['email'], 'name' => $attributes['name'], 'avatar' => $attributes['avatar']])
        ->setToken($attributes['token'])
        ->setRefreshToken($attributes['refresh_token'])
        ->setExpiresIn($attributes['expires_in'])
        ->setApprovedScopes($attributes['scopes']);

    return $user;
}

beforeEach(function () {
    config([
        'services.google.client_id' => 'client-id.apps.googleusercontent.com',
        'services.google.client_secret' => 'secret',
        'services.google.redirect' => 'http://localhost:8000/settings/connections/google/callback',
    ]);

    $this->user = User::factory()->create();
});

test('guests can not start the authorisation', function () {
    auth()->logout();

    $this->get(route('settings.connections.redirect', 'google'))->assertRedirect(route('login'));
});

test('users are sent to google asking for offline, read-only access and an account choice', function () {
    $response = $this->actingAs($this->user)->get(route('settings.connections.redirect', 'google'));

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/auth')
        ->and($query['client_id'])->toBe('client-id.apps.googleusercontent.com')
        ->and($query['redirect_uri'])->toBe('http://localhost:8000/settings/connections/google/callback')
        ->and($query['access_type'])->toBe('offline')
        ->and($query['prompt'])->toBe('consent select_account')
        ->and($query['state'])->not->toBeEmpty()
        ->and(explode(' ', $query['scope']))->toContain(...Provider::Google->scopes());
});

test('providers that can not be connected yet are not found', function (string $provider) {
    $this->actingAs($this->user)->get(route('settings.connections.redirect', $provider))->assertNotFound();
})->with(['atlassian', 'unknown']);

test('the callback stores the google account with encrypted tokens', function () {
    googleReturns();

    $this->actingAs($this->user)
        ->get(route('settings.connections.callback', 'google'))
        ->assertRedirect(route('settings.connections.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Cuenta jhon@example.com conectada.']);

    $account = $this->user->connectedAccounts()->sole();

    expect($account->provider)->toBe(Provider::Google)
        ->and($account->external_account_id)->toBe('1234567890')
        ->and($account->email)->toBe('jhon@example.com')
        ->and($account->access_token)->toBe('ya29.access')
        ->and($account->refresh_token)->toBe('1//refresh')
        ->and($account->status)->toBe(AccountStatus::Active)
        ->and($account->expires_at->diffInSeconds(now()->addSeconds(3599)))->toBeLessThan(5)
        ->and($account->missingScopes())->toBe([]);

    $raw = DB::table('connected_accounts')->where('id', $account->id)->first();

    expect($raw->access_token)->not->toContain('ya29.access')
        ->and($raw->refresh_token)->not->toContain('1//refresh');
});

test('a user can connect several google accounts', function () {
    Socialite::shouldReceive('driver->user')->andReturn(
        googleUser(['id' => '111', 'email' => 'work@example.com']),
        googleUser(['id' => '222', 'email' => 'personal@example.com']),
    );

    $this->actingAs($this->user)->get(route('settings.connections.callback', 'google'));
    $this->actingAs($this->user)->get(route('settings.connections.callback', 'google'));

    expect($this->user->connectedAccounts()->pluck('email')->all())
        ->toEqualCanonicalizing(['work@example.com', 'personal@example.com']);
});

test('different users can connect the same google account', function () {
    $otherUser = User::factory()->create();

    googleReturns();
    $this->actingAs($this->user)->get(route('settings.connections.callback', 'google'));
    $this->actingAs($otherUser)->get(route('settings.connections.callback', 'google'));

    expect(ConnectedAccount::where('external_account_id', '1234567890')->count())->toBe(2);
});

test('connecting an account again updates it and keeps its refresh token', function () {
    $account = ConnectedAccount::factory()->needsReconnection()->for($this->user)->create([
        'external_account_id' => '1234567890',
        'refresh_token' => '1//original',
    ]);

    googleReturns(['token' => 'ya29.new', 'refresh_token' => null]);

    $this->actingAs($this->user)
        ->get(route('settings.connections.callback', 'google'))
        ->assertInertiaFlash('toast.message', 'Cuenta jhon@example.com actualizada.');

    $account->refresh();

    expect($this->user->connectedAccounts()->count())->toBe(1)
        ->and($account->access_token)->toBe('ya29.new')
        ->and($account->refresh_token)->toBe('1//original')
        ->and($account->status)->toBe(AccountStatus::Active);
});

test('users are warned when they do not grant every permission', function () {
    googleReturns(['scopes' => ['openid', 'https://www.googleapis.com/auth/calendar.readonly']]);

    $this->actingAs($this->user)
        ->get(route('settings.connections.callback', 'google'))
        ->assertInertiaFlash('toast.type', 'warning');

    expect($this->user->connectedAccounts()->sole()->missingScopes())->toHaveCount(count(Provider::Google->scopes()) - 1);
});

test('nothing is stored when the user cancels at google', function () {
    $this->actingAs($this->user)
        ->get(route('settings.connections.callback', ['provider' => 'google', 'error' => 'access_denied']))
        ->assertRedirect(route('settings.connections.index'))
        ->assertInertiaFlash('toast.type', 'warning');

    expect(ConnectedAccount::count())->toBe(0);
});

test('a failed or forged callback stores nothing', function () {
    Socialite::shouldReceive('driver->user')->andThrow(new InvalidStateException);

    $this->actingAs($this->user)
        ->get(route('settings.connections.callback', 'google'))
        ->assertRedirect(route('settings.connections.index'))
        ->assertInertiaFlash('toast.type', 'error');

    expect(ConnectedAccount::count())->toBe(0);
});

test('the connections page only lists the user own accounts', function () {
    ConnectedAccount::factory()->for($this->user)->create(['email' => 'mine@example.com']);
    ConnectedAccount::factory()->create(['email' => 'someone-else@example.com']);

    $this->actingAs($this->user)
        ->get(route('settings.connections.index'))
        ->assertInertia(fn ($page) => $page
            ->has('providers.0.accounts', 1)
            ->where('providers.0.accounts.0.email', 'mine@example.com')
            ->where('providers.0.accounts.0.status', 'active')
            ->missing('providers.0.accounts.0.access_token')
            ->missing('providers.0.accounts.0.refresh_token'));
});
