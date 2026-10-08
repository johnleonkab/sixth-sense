<?php

use App\Jobs\BuildWorkContext;
use App\Models\User;
use App\Models\WorkContext;
use App\Voice\Providers\FakeVoiceProvider;
use App\Voice\VoiceProvider;
use App\WorkContext\WorkContextStatus;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create(['name' => 'John León']);
});

test('the context page starts switched off and never built', function () {
    $this->actingAs($this->user)->get(route('settings.context.index'))
        ->assertInertia(fn ($page) => $page->component('settings/context')
            ->where('context.enabled', false)
            ->where('context.status', 'idle')
            ->where('context.status_label', 'Sin generar')
            ->where('context.digest', null)
            ->where('run_at', config('work_context.run_at')));
});

test('the context page shows what the assistant knows', function () {
    WorkContext::factory()->for($this->user)->ready()->create();

    $this->actingAs($this->user)->get(route('settings.context.index'))
        ->assertInertia(fn ($page) => $page
            ->where('context.status', 'ready')
            ->where('context.usable', true)
            ->where('context.sources.slack', 42)
            ->where('context.digest.workstreams.0.name', 'Lanzamiento del prepago')
            ->where('context.digest.open_loops', ['Confirmar a Pablo el reembolso parcial']));
});

test('a build left running by a dead worker shows as interrupted', function () {
    WorkContext::factory()->for($this->user)->create([
        'status' => WorkContextStatus::Running,
        'started_at' => now()->subMinutes(WorkContext::BUILD_STALE_AFTER_MINUTES + 1),
    ]);

    $this->actingAs($this->user)->get(route('settings.context.index'))
        ->assertInertia(fn ($page) => $page
            ->where('context.status', 'failed')
            ->where('context.error', 'Se interrumpió antes de terminar. Vuelve a generarlo.'));

    $this->actingAs($this->user)->post(route('settings.context.build'))->assertRedirect();

    Queue::assertPushed(BuildWorkContext::class);
});

test('switching it on builds it right away the first time', function () {
    $this->actingAs($this->user)->put(route('settings.context.update'), ['enabled' => true])->assertRedirect();

    expect($this->user->workContext()->first()->enabled)->toBeTrue();
    Queue::assertPushed(BuildWorkContext::class, fn (BuildWorkContext $job) => $job->user->is($this->user));
});

test('switching it on again waits for the night when it was already built', function () {
    WorkContext::factory()->for($this->user)->ready()->create(['enabled' => false]);

    $this->actingAs($this->user)->put(route('settings.context.update'), ['enabled' => true])->assertRedirect();

    Queue::assertNothingPushed();
});

test('switching it off keeps it from the assistant', function () {
    WorkContext::factory()->for($this->user)->ready()->create();

    $this->actingAs($this->user)->put(route('settings.context.update'), ['enabled' => false])->assertRedirect();

    expect($this->user->workContext()->first()->usable())->toBeFalse();
});

test('it can be built again now, but not while building or switched off', function () {
    $context = WorkContext::factory()->for($this->user)->ready()->create();

    $this->actingAs($this->user)->post(route('settings.context.build'))->assertRedirect();
    Queue::assertPushed(BuildWorkContext::class, 1);

    $context->update(['status' => WorkContextStatus::Running, 'started_at' => now()]);
    $this->actingAs($this->user)->post(route('settings.context.build'))->assertRedirect();
    Queue::assertPushed(BuildWorkContext::class, 1);

    $context->update(['enabled' => false, 'status' => WorkContextStatus::Ready]);
    $this->actingAs($this->user)->post(route('settings.context.build'))->assertConflict();
});

test('the assistant starts each conversation knowing what the person is working on', function () {
    $this->app->instance(VoiceProvider::class, $voice = new FakeVoiceProvider);
    WorkContext::factory()->for($this->user)->ready()->create();

    $this->actingAs($this->user)->postJson(route('voice.sessions.store'))->assertCreated();

    expect($voice->started[0]['assistant']->instructions)
        ->toContain('Contexto de su trabajo estos días')
        ->toContain('Resumen: Esta semana John está cerrando el lanzamiento del prepago')
        ->toContain('- Lanzamiento del prepago: Revisión del flujo antes del 20 de octubre. Por qué: Reducir los no-shows. Con: Marta Ruiz, Pablo Gil.')
        ->toContain('Personas: Marta Ruiz (Product Manager, lleva el prepago).')
        ->toContain('Pendiente: Confirmar a Pablo el reembolso parcial.');
});

test('the assistant does not get the context when it is off or old', function (array $state) {
    $this->app->instance(VoiceProvider::class, $voice = new FakeVoiceProvider);
    WorkContext::factory()->for($this->user)->ready()->create($state);

    $this->actingAs($this->user)->postJson(route('voice.sessions.store'))->assertCreated();

    expect($voice->started[0]['assistant']->instructions)->not->toContain('Contexto de su trabajo');
})->with([
    'off' => [['enabled' => false]],
    'old' => [['generated_at' => now()->subDays(4)]],
]);
