<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests can not open the settings', function () {
    $this->get(route('settings.connections.index'))->assertRedirect(route('login'));
});

test('the settings open on the connections section', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.index'))
        ->assertRedirect(route('settings.connections.index'));
});

test('the connections page lists every provider with its accounts', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.connections.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/connections')
            ->has('providers', 5)
            ->has('providers.0', fn (Assert $provider) => $provider
                ->where('key', 'google')
                ->where('name', 'Google')
                ->where('services', ['Calendar', 'Gmail', 'Docs', 'Sheets'])
                ->where('connectable', true)
                ->where('accounts', []))
            ->where('providers.1.key', 'slack')
            ->where('providers.2.key', 'atlassian')
            ->where('providers.3.key', 'notion')
            ->where('providers.4.key', 'github'));
});
