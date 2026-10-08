<?php

use App\Models\User;
use App\Voice\AssistantProfile;
use App\Voice\Providers\FakeVoiceProvider;
use App\Voice\VoiceProvider;
use App\Voice\VoiceProviderUnavailable;
use App\Voice\VoiceSession;
use Carbon\CarbonImmutable;

test('guests can not start a voice session', function () {
    $this->postJson(route('voice.sessions.store'))->assertUnauthorized();
});

test('users get a short-lived grant from the configured provider', function () {
    $this->app->instance(VoiceProvider::class, $voice = new FakeVoiceProvider);
    $user = User::factory()->create(['name' => 'John León']);

    $this->actingAs($user)
        ->postJson(route('voice.sessions.store'))
        ->assertCreated()
        ->assertJson([
            'provider' => 'fake',
            'token' => 'fake-token-1',
        ])
        ->assertJsonStructure(['provider', 'token', 'connect_before', 'expires_at', 'connection']);

    expect($voice->started)->toHaveCount(1)
        ->and($voice->started[0]['user']->is($user))->toBeTrue()
        ->and($voice->started[0]['assistant']->instructions)->toContain('John León');
});

test('the assistant knows the date and time in the browser timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 20:45:00', 'UTC'));
    $this->app->instance(VoiceProvider::class, $voice = new FakeVoiceProvider);

    $this->actingAs(User::factory()->create())
        ->postJson(route('voice.sessions.store'), ['timezone' => 'Europe/Madrid'])
        ->assertCreated();

    expect($voice->started[0]['assistant']->instructions)
        ->toContain('miércoles, 7 de octubre de 2026 a las 22:45 (zona horaria Europe/Madrid)');
});

test('the app timezone is used when the browser does not send one', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 20:45:00', 'UTC'));
    $this->app->instance(VoiceProvider::class, $voice = new FakeVoiceProvider);

    $this->actingAs(User::factory()->create())
        ->postJson(route('voice.sessions.store'))
        ->assertCreated();

    expect($voice->started[0]['assistant']->instructions)->toContain('a las 20:45 (zona horaria UTC)');
});

test('an invalid timezone is rejected', function () {
    $this->app->instance(VoiceProvider::class, new FakeVoiceProvider);

    $this->actingAs(User::factory()->create())
        ->postJson(route('voice.sessions.store'), ['timezone' => 'Mars/Olympus'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('timezone');
});

test('users get a friendly error when the provider is unavailable', function () {
    $this->app->instance(VoiceProvider::class, new class implements VoiceProvider
    {
        public function startSession(User $user, AssistantProfile $assistant): VoiceSession
        {
            throw VoiceProviderUnavailable::failed('test', 'down');
        }
    });

    $this->actingAs(User::factory()->create())
        ->postJson(route('voice.sessions.store'))
        ->assertServiceUnavailable()
        ->assertJson(['message' => 'No puedo conectar con el servicio de voz ahora mismo. Inténtalo de nuevo en un momento.']);
});

test('the fake provider can be selected through configuration', function () {
    config(['voice.default' => 'fake']);
    $this->app->forgetInstance(VoiceProvider::class);

    expect(app(VoiceProvider::class))->toBeInstanceOf(FakeVoiceProvider::class);
});
