<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login screen', function () {
    $this->get(route('settings.connections.index'))->assertRedirect(route('login'));
});

test('the login screen can be rendered', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/login'));
});

test('users can log in with valid credentials', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('home', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('users are sent to the page they originally asked for', function () {
    $user = User::factory()->create();

    $this->get(route('home'));

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('home'));
});

test('users can not log in with an invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors(['email' => 'El email o la contraseña no son correctos.']);

    $this->assertGuest();
});

test('email and password are required', function () {
    $this->post(route('login.store'), [])
        ->assertSessionHasErrors([
            'email' => 'Escribe tu email.',
            'password' => 'Escribe tu contraseña.',
        ]);
});

test('login is locked after too many failed attempts', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);
    }

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('authenticated users see the home screen with their shared data', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->where('auth.user.email', $user->email)
            ->missing('auth.user.password'));
});

test('authenticated users are redirected away from the login screen', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('login'))->assertRedirect('/');
});

test('users can log out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
