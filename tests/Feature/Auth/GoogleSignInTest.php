<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OAuthUser;

beforeEach(function () {
    config([
        'services.google.client_id' => 'client-id.apps.googleusercontent.com',
        'services.google.client_secret' => 'secret',
        'services.google.redirect' => 'http://localhost:8000/settings/connections/google/callback',
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function googleSignInReturns(array $overrides = []): void
{
    $attributes = [
        'id' => 'google-1',
        'email' => 'john.leon@zenchef.com',
        'name' => 'John León',
        'avatar' => 'https://example.com/a.png',
        'email_verified' => true,
        ...$overrides,
    ];

    $user = (new OAuthUser)->map([
        'id' => $attributes['id'],
        'email' => $attributes['email'],
        'name' => $attributes['name'],
        'avatar' => $attributes['avatar'],
    ]);
    $user->user = ['email_verified' => $attributes['email_verified']];

    Socialite::fake('google', $user);
}

test('the login screen offers google when it is configured', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('google', true));
});

test('signing in asks google only who the person is', function () {
    $response = $this->get(route('auth.google.redirect'));

    $response->assertRedirect();
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($response->headers->get('Location'))->toStartWith('https://accounts.google.com/o/oauth2/auth')
        ->and($query['redirect_uri'])->toBe(route('auth.google.callback'))
        ->and($query['prompt'])->toBe('select_account')
        ->and(explode(' ', $query['scope']))->toBe(['openid', 'profile', 'email']);
});

test('an existing account signs in and keeps its password', function () {
    $user = User::factory()->create(['email' => 'John.Leon@zenchef.com']);

    googleSignInReturns();

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('home', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($user->refresh()->google_id)->toBe('google-1')
        ->and($user->password)->not->toBeNull();
});

test('the same google account signs in again', function () {
    $user = User::factory()->create([
        'email' => 'john.leon@zenchef.com',
        'google_id' => 'google-1',
    ]);

    googleSignInReturns();

    $this->get(route('auth.google.callback'))->assertRedirect(route('home', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe(1);
});

test('an unknown email is refused while registration is closed', function () {
    config(['auth.registration' => false]);
    googleSignInReturns();

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => 'No hay una cuenta con este email.']);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

test('an unknown email creates an account while registration is open', function () {
    config(['auth.registration' => true]);
    googleSignInReturns();

    $this->get(route('auth.google.callback'))->assertRedirect(route('home', absolute: false));

    $user = User::query()->where('email', 'john.leon@zenchef.com')->firstOrFail();

    $this->assertAuthenticatedAs($user);
    expect($user->google_id)->toBe('google-1')
        ->and($user->name)->toBe('John León')
        ->and($user->email_verified_at)->not->toBeNull();
});

test('an unverified google email is refused', function () {
    User::factory()->create(['email' => 'john.leon@zenchef.com']);
    googleSignInReturns(['email_verified' => false]);

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => 'Google no ha confirmado este email.']);

    $this->assertGuest();
});

test('google sign in is missing when google is not configured', function () {
    config(['services.google.client_id' => null]);

    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('google', false));
    $this->get(route('auth.google.redirect'))->assertNotFound();
});
