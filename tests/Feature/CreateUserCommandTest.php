<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('it creates the user', function () {
    $this->artisan('app:create-user', [
        '--name' => 'Jhon',
        '--email' => 'jhon@example.com',
        '--password' => 'secret-password',
    ])->assertSuccessful();

    $user = User::sole();

    expect($user->name)->toBe('Jhon')
        ->and($user->email)->toBe('jhon@example.com')
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();
});

test('it resets the password of an existing user', function () {
    $user = User::factory()->create(['email' => 'jhon@example.com']);

    $this->artisan('app:create-user', [
        '--email' => 'jhon@example.com',
        '--password' => 'new-password',
    ])->assertSuccessful();

    expect(User::count())->toBe(1)
        ->and(Hash::check('new-password', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->name)->toBe($user->name);
});

test('it rejects short passwords', function () {
    $this->artisan('app:create-user', [
        '--name' => 'Jhon',
        '--email' => 'jhon@example.com',
        '--password' => 'short',
    ])->assertFailed();

    expect(User::count())->toBe(0);
});
