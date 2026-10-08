<?php

use App\Alerts\AlertSource;
use App\Connections\Capability;
use App\Models\ConnectedAccount;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('guests can not open the alert settings', function () {
    auth()->logout();

    $this->get(route('settings.alerts.index'))->assertRedirect(route('login'));
});

test('gmail alerts are off until the user switches them on', function () {
    expect($this->user->wantsAlertsFrom(AlertSource::Gmail))->toBeFalse();

    $this->actingAs($this->user)
        ->get(route('settings.alerts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/alerts')
            ->where('sources.0.key', 'gmail')
            ->where('sources.0.enabled', false)
            ->where('sources.0.capability', 'Leer el correo'));
});

test('users can switch gmail alerts on and off', function () {
    $this->actingAs($this->user)->from(route('settings.alerts.index'))
        ->put(route('settings.alerts.update', 'gmail'), ['enabled' => true])
        ->assertRedirect(route('settings.alerts.index'));

    expect($this->user->wantsAlertsFrom(AlertSource::Gmail))->toBeTrue();

    $this->actingAs($this->user)->put(route('settings.alerts.update', 'gmail'), ['enabled' => false]);

    expect($this->user->wantsAlertsFrom(AlertSource::Gmail))->toBeFalse()
        ->and($this->user->alertSettings()->count())->toBe(1);
});

test('it shows which gmail accounts will be watched', function () {
    $work = ConnectedAccount::factory()->for($this->user)->create(['email' => 'work@example.com']);
    $personal = ConnectedAccount::factory()->for($this->user)->create(['email' => 'personal@example.com']);
    $personal->toggleCapability(Capability::GmailRead, false);
    ConnectedAccount::factory()->create(['email' => 'someone-else@example.com']);

    $this->actingAs($this->user)
        ->get(route('settings.alerts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('sources.0.accounts', 2)
            ->where('sources.0.accounts.0', ['id' => $work->id, 'email' => 'work@example.com', 'watched' => true])
            ->where('sources.0.accounts.1.watched', false));
});

test('settings of one user do not affect another', function () {
    $this->actingAs(User::factory()->create())->put(route('settings.alerts.update', 'gmail'), ['enabled' => true]);

    expect($this->user->wantsAlertsFrom(AlertSource::Gmail))->toBeFalse();
});

test('unknown sources are not found', function () {
    $this->actingAs($this->user)->put('/settings/alerts/fax', ['enabled' => true])->assertNotFound();
});
