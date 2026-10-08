<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function signUp(array $overrides = []): array
{
    return [
        'name' => 'Marta Ruiz',
        'email' => 'marta@covermanager.test',
        'password' => 'prepago-2026',
        'password_confirmation' => 'prepago-2026',
        ...$overrides,
    ];
}

test('with registration off, nobody can sign up and the login does not offer it', function () {
    config(['auth.registration' => false]);

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('canRegister', false));
    $this->get(route('register'))->assertNotFound();
    $this->post(route('register.store'), signUp())->assertNotFound();

    expect(User::count())->toBe(0);
    $this->assertGuest();
});

test('with registration on, the login offers it and the sign-up screen can be rendered', function () {
    config(['auth.registration' => true]);

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('canRegister', true));
    $this->get(route('register'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/register'));
});

test('people can sign up and start using the assistant', function () {
    config(['auth.registration' => true]);

    $this->post(route('register.store'), signUp(['email' => '  Marta@CoverManager.test ']))
        ->assertRedirect(route('home'));

    $user = User::sole();

    expect($user->name)->toBe('Marta Ruiz')
        ->and($user->email)->toBe('marta@covermanager.test');
    $this->assertAuthenticatedAs($user);
});

test('an email can only have one account', function () {
    config(['auth.registration' => true]);
    User::factory()->create(['email' => 'marta@covermanager.test']);

    $this->post(route('register.store'), signUp(['email' => 'MARTA@covermanager.test']))
        ->assertSessionHasErrors(['email' => 'Ya hay una cuenta con ese email.']);

    $this->assertGuest();
});

test('the password needs 8 characters and to be repeated the same', function () {
    config(['auth.registration' => true]);

    $this->post(route('register.store'), signUp(['password' => 'corta', 'password_confirmation' => 'corta']))
        ->assertSessionHasErrors(['password' => 'La contraseña necesita al menos 8 caracteres.']);

    $this->post(route('register.store'), signUp(['password_confirmation' => 'otra-distinta']))
        ->assertSessionHasErrors(['password' => 'Las contraseñas no coinciden.']);

    $this->assertGuest();
});

test('signed-in users are sent away from the sign-up screen', function () {
    config(['auth.registration' => true]);

    $this->actingAs(User::factory()->create())->get(route('register'))->assertRedirect('/');
});
