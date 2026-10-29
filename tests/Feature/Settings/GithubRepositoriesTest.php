<?php

use App\Connections\Capability;
use App\Connections\Provider;
use App\Models\GithubRepository;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = $this->user->connectedAccounts()->create([
        'provider' => Provider::GitHub,
        'external_account_id' => '42',
        'email' => 'jhon@covermanager.com',
        'name' => 'John León',
        'access_token' => 'gho_1',
        'scopes' => Provider::GitHub->scopes(),
        'metadata' => ['login' => 'jhon'],
        'status' => 'active',
    ]);
    $this->writable = GithubRepository::factory()->for($this->account, 'account')->writable()->create([
        'github_id' => 7,
        'owner' => 'jhon',
        'name' => 'sixth-sense',
        'full_name' => 'jhon/sixth-sense',
    ]);
    $this->readOnly = GithubRepository::factory()->for($this->account, 'account')->create([
        'github_id' => 8,
        'owner' => 'jhon',
        'name' => 'notes',
        'full_name' => 'jhon/notes',
        'can_push' => false,
    ]);
});

test('the connections page lists each github repository with what the person allows', function () {
    $this->actingAs($this->user)
        ->get(route('settings.connections.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('providers.4.key', 'github')
            ->where('providers.4.accounts.0.workspace', 'jhon')
            ->where('providers.4.accounts.0.repositories.0.full_name', 'jhon/notes')
            ->where('providers.4.accounts.0.repositories.0.can_push', false)
            ->where('providers.4.accounts.0.repositories.1.full_name', 'jhon/sixth-sense')
            ->where('providers.4.accounts.0.repositories.1.writes_code', true));
});

test('a repository can be included and allowed to update code', function () {
    $this->actingAs($this->user)
        ->put(route('settings.connections.repositories.update', [$this->account, $this->readOnly]), [
            'included' => true,
            'writes_code' => false,
        ])
        ->assertRedirect();

    expect($this->readOnly->fresh()->included)->toBeTrue()
        ->and($this->readOnly->fresh()->writes_code)->toBeFalse();

    $this->actingAs($this->user)
        ->put(route('settings.connections.repositories.update', [$this->account, $this->writable]), [
            'included' => true,
            'writes_code' => true,
        ])
        ->assertRedirect();

    expect($this->writable->fresh()->mayUpdateCode())->toBeTrue();
});

test('code updates stay off when the account cannot push or the capability is switched off', function () {
    $this->actingAs($this->user)
        ->from(route('settings.connections.index'))
        ->put(route('settings.connections.repositories.update', [$this->account, $this->readOnly]), [
            'included' => true,
            'writes_code' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('writes_code');

    expect($this->readOnly->fresh()->writes_code)->toBeFalse();

    $this->account->toggleCapability(Capability::GithubWrite, false);

    $this->actingAs($this->user)
        ->from(route('settings.connections.index'))
        ->put(route('settings.connections.repositories.update', [$this->account, $this->writable]), [
            'included' => true,
            'writes_code' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('writes_code');
});

test('leaving a repository out also removes permission to update its code', function () {
    $this->actingAs($this->user)
        ->put(route('settings.connections.repositories.update', [$this->account, $this->writable]), [
            'included' => false,
            'writes_code' => true,
        ])
        ->assertRedirect();

    expect($this->writable->fresh()->included)->toBeFalse()
        ->and($this->writable->fresh()->writes_code)->toBeFalse()
        ->and($this->writable->fresh()->mayUpdateCode())->toBeFalse();
});

test('refreshing the list keeps choices and drops repositories that disappeared', function () {
    Http::fake([
        'api.github.com/user/repos*' => Http::response([
            ['id' => 7, 'name' => 'sixth-sense', 'full_name' => 'jhon/sixth-sense', 'private' => true, 'default_branch' => 'main', 'owner' => ['login' => 'jhon'], 'permissions' => ['push' => true]],
            ['id' => 9, 'name' => 'api', 'full_name' => 'jhon/api', 'private' => true, 'default_branch' => 'main', 'owner' => ['login' => 'jhon'], 'permissions' => ['push' => true]],
        ]),
    ]);

    $this->actingAs($this->user)
        ->post(route('settings.connections.repositories.refresh', $this->account))
        ->assertRedirect();

    expect($this->account->githubRepositories()->pluck('full_name')->all())->toEqualCanonicalizing(['jhon/sixth-sense', 'jhon/api'])
        ->and($this->writable->fresh()->writes_code)->toBeTrue()
        ->and(GithubRepository::where('full_name', 'jhon/notes')->exists())->toBeFalse();
});

test('another person cannot change which repositories an account uses', function () {
    $other = User::factory()->create();

    $this->actingAs($other)
        ->put(route('settings.connections.repositories.update', [$this->account, $this->writable]), [
            'included' => false,
            'writes_code' => false,
        ])
        ->assertNotFound();
});
