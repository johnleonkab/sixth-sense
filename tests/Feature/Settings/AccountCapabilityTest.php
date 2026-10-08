<?php

use App\Connections\Capability;
use App\Connections\Provider;
use App\Models\ConnectedAccount;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('users can switch a capability off and on again', function () {
    $account = ConnectedAccount::factory()->for($this->user)->create();
    $url = route('settings.connections.capabilities.update', [$account, Capability::GmailRead]);

    $this->actingAs($this->user)->from(route('settings.connections.index'))
        ->put($url, ['enabled' => false])
        ->assertRedirect(route('settings.connections.index'));

    expect($account->refresh()->can(Capability::GmailRead))->toBeFalse()
        ->and($account->can(Capability::CalendarRead))->toBeTrue();

    $this->actingAs($this->user)->put($url, ['enabled' => true]);

    expect($account->refresh()->can(Capability::GmailRead))->toBeTrue()
        ->and($account->disabled_capabilities)->toBe([]);
});

test('a capability the provider did not grant can not be switched on', function () {
    $account = ConnectedAccount::factory()->for($this->user)->create(['scopes' => [Capability::CalendarRead->scope()]]);

    $this->actingAs($this->user)
        ->put(route('settings.connections.capabilities.update', [$account, Capability::GmailRead]), ['enabled' => true])
        ->assertSessionHasErrors('enabled');

    expect($account->refresh()->can(Capability::GmailRead))->toBeFalse();
});

test('the connections page shows each account capabilities', function () {
    ConnectedAccount::factory()->for($this->user)->create([
        'scopes' => [Capability::CalendarRead->scope(), Capability::GmailRead->scope()],
        'disabled_capabilities' => [Capability::GmailRead->value],
    ]);

    $this->actingAs($this->user)
        ->get(route('settings.connections.index'))
        ->assertInertia(fn ($page) => $page
            ->has('providers.0.accounts.0.capabilities', count(Capability::of(Provider::Google)))
            ->where('providers.0.accounts.0.capabilities.0', ['key' => 'calendar.read', 'label' => 'Ver el calendario', 'granted' => true, 'enabled' => true])
            ->where('providers.0.accounts.0.capabilities.1', ['key' => 'gmail.read', 'label' => 'Leer el correo', 'granted' => true, 'enabled' => false])
            ->where('providers.0.accounts.0.capabilities.2.granted', false));
});

test('users can not change someone else account', function () {
    $account = ConnectedAccount::factory()->create();

    $this->actingAs($this->user)
        ->put(route('settings.connections.capabilities.update', [$account, Capability::GmailRead]), ['enabled' => false])
        ->assertNotFound();
});

test('unknown capabilities are not found', function () {
    $account = ConnectedAccount::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->put("/settings/connections/{$account->id}/capabilities/slack.read", ['enabled' => false])
        ->assertNotFound();
});
