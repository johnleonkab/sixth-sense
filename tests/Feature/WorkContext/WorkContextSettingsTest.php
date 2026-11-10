<?php

use App\Comms\Context\ContextHarvest;
use App\Comms\Context\ContextWriter;
use App\Comms\Context\FactOrigin;
use App\Knowledge\KnowledgeSource;
use App\Models\Comms\CommsContextFact;
use App\Models\KnowledgeDocument;
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
            ->where('tab', 'people')
            ->where('known', [])
            ->where('run_at', config('work_context.run_at')));
});

test('the context page shows what the assistant knows, where it came from', function () {
    WorkContext::factory()->for($this->user)->ready()->create();

    $this->actingAs($this->user)->get(route('settings.context.index', ['tab' => 'known']))
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'known')
            ->where('context.status', 'ready')
            ->where('context.recent', true)
            ->where('context.sources.slack', 42)
            ->where('known', fn ($known): bool => collect($known)->contains(fn (array $fact): bool => $fact['attribute'] === 'state'
                && $fact['value']['name'] === 'Lanzamiento del prepago'
                && $fact['origin'] === 'inferred'
                && str_starts_with($fact['refs'][0], 'harvest:'))));
});

test('people and lessons now live in the one context section', function () {
    $this->actingAs($this->user)->get('/settings/people')->assertRedirect('/settings/context?tab=people');
    $this->actingAs($this->user)->get('/settings/lessons')->assertRedirect('/settings/context?tab=rules');
});

test('a fact can be corrected, deleted, and a guess that contradicts the person accepted', function () {
    $writer = app(ContextWriter::class);
    $writer->rule($this->user, 'Con Marta, tono informal.', 'reply', 'Marta');
    $rule = CommsContextFact::query()->sole();

    $this->actingAs($this->user)->get(route('settings.context.index', ['tab' => 'rules']))
        ->assertInertia(fn ($page) => $page->where('rules.0.text', 'Con Marta, tono informal.')->where('rules.0.audience_labels', ['Respuestas']));

    $this->actingAs($this->user)->put(route('settings.context.facts.edit', $rule->id), ['text' => 'Con Marta, tono informal y breve.'])->assertRedirect();

    $edited = CommsContextFact::query()->where('status', 'active')->sole();
    expect($edited->text())->toBe('Con Marta, tono informal y breve.')
        ->and($edited->origin)->toBe(FactOrigin::User);

    $this->actingAs($this->user)->post(route('settings.context.facts.reject', $edited->id))->assertRedirect();
    expect(CommsContextFact::query()->where('status', 'active')->count())->toBe(0);

    $relation = fn (string $value): array => [['op' => 'set', 'kind' => 'person', 'subject' => 'person:pablo', 'attribute' => 'relation', 'value' => ['value' => $value], 'audiences' => []]];
    $writer->record($this->user, FactOrigin::User, $relation('client'));
    $writer->record($this->user, FactOrigin::Inferred, $relation('boss'));

    $this->actingAs($this->user)->get(route('settings.context.index', ['tab' => 'pending']))
        ->assertInertia(fn ($page) => $page->where('pending.0.text', 'boss')->where('pending.0.contradicts', 'client'));

    $proposed = CommsContextFact::query()->where('status', 'proposed')->sole();
    $this->actingAs($this->user)->post(route('settings.context.facts.accept', $proposed->id))->assertRedirect();

    expect(CommsContextFact::query()->where('subject', 'person:pablo')->where('status', 'active')->sole()->text())->toBe('boss');
});

test('a fact of someone else cannot be touched', function () {
    app(ContextWriter::class)->rule(User::factory()->create(), 'Nada de emojis.', 'reply');

    $this->actingAs($this->user)->post(route('settings.context.facts.reject', CommsContextFact::query()->sole()->id))->assertNotFound();
});

test('the context page shows how much of each knowledge source is copied', function () {
    KnowledgeDocument::factory()->for($this->user)->create(['source' => KnowledgeSource::Jira, 'indexed_at' => '2026-10-29 21:24:08']);
    KnowledgeDocument::factory()->for($this->user)->create(['source' => KnowledgeSource::Jira, 'indexed_at' => null, 'terms' => null]);
    KnowledgeDocument::factory()->for($this->user)->create(['source' => KnowledgeSource::Archive]);
    KnowledgeDocument::factory()->create(['source' => KnowledgeSource::Notion]);

    $this->actingAs($this->user)->get(route('settings.context.index'))
        ->assertInertia(fn ($page) => $page
            ->has('knowledge', 5)
            ->where('knowledge.0.source', 'archive')
            ->where('knowledge.0.total', 1)
            ->where('knowledge.0.copied', 1)
            ->where('knowledge.1.label', 'Jira')
            ->where('knowledge.1.total', 2)
            ->where('knowledge.1.copied', 1)
            ->where('knowledge.1.last_copied_at', fn (string $at): bool => str_starts_with($at, '2026-10-29T21:24:08'))
            ->where('knowledge.2.source', 'notion')
            ->where('knowledge.2.total', 0)
            ->where('knowledge.2.last_copied_at', null));
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

    Queue::assertPushed(ContextHarvest::class);
});

test('switching it on builds it right away the first time', function () {
    $this->actingAs($this->user)->put(route('settings.context.update'), ['enabled' => true])->assertRedirect();

    expect($this->user->workContext()->first()->enabled)->toBeTrue();
    Queue::assertPushed(ContextHarvest::class, fn (ContextHarvest $job) => $job->user->is($this->user)
        && $job->connection === 'redis-long'
        && $job->queue === 'context');
});

test('switching it on again waits for the night when it was already built', function () {
    WorkContext::factory()->for($this->user)->ready()->create(['enabled' => false]);

    $this->actingAs($this->user)->put(route('settings.context.update'), ['enabled' => true])->assertRedirect();

    Queue::assertNotPushed(ContextHarvest::class);
});

test('switching it off keeps it from the assistant', function () {
    WorkContext::factory()->for($this->user)->ready()->create();

    $this->actingAs($this->user)->put(route('settings.context.update'), ['enabled' => false])->assertRedirect();

    expect($this->user->workContext()->first()->recent())->toBeFalse();
});

test('it can be built again now, but not while building or switched off', function () {
    $context = WorkContext::factory()->for($this->user)->ready()->create();

    $this->actingAs($this->user)->post(route('settings.context.build'))->assertRedirect();
    Queue::assertPushed(ContextHarvest::class, 1);

    $context->update(['status' => WorkContextStatus::Running, 'started_at' => now()]);
    $this->actingAs($this->user)->post(route('settings.context.build'))->assertRedirect();
    Queue::assertPushed(ContextHarvest::class, 1);

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
        ->toContain('Personas: Marta Ruiz (Product Manager, lleva el prepago).');
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
