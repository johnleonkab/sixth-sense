<?php

use App\Comms\Context\ContextWriter;
use App\Comms\Context\FactOrigin;
use App\Comms\Events\EventType;
use App\Comms\Events\Replay;
use App\Models\Comms\CommsContextFact;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsTopicAlias;
use App\Models\Comms\CommsTopicSearch;
use App\Models\User;

test('the Temas screen lists a proposed topic and skips a digest workstream', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->create(['user_id' => $user->id, 'display_name' => 'Marta Ruiz']);
    $writer = app(ContextWriter::class);
    $writer->record($user, FactOrigin::Inferred, topicScreenOps('El prepago antes del 20.', ['person:'.$marta->id]));
    $writer->record($user, FactOrigin::Inferred, [[
        'op' => 'set',
        'kind' => 'workstream',
        'subject' => 'workstream:digest',
        'attribute' => 'state',
        'value' => ['text' => 'Otra cosa', 'name' => 'Otra cosa'],
        'audiences' => [],
        'status' => 'proposed',
    ]]);

    $this->actingAs($user)->get(route('settings.context.index', ['tab' => 'topics']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/context')
            ->where('tab', 'topics')
            ->has('topics', 1)
            ->where('topics.0.subject', 'workstream:prepago')
            ->where('topics.0.name', 'Lanzamiento de primavera')
            ->where('topics.0.summary', 'El prepago antes del 20.')
            ->where('topics.0.state', 'in progress')
            ->where('topics.0.people', ['Marta Ruiz'])
            ->where('topics.0.channels', ['slack:C1'])
            ->where('topics.0.proposed', true)
            ->where('topics.0.nicknames.0.alias', 'SPR')
            ->where('topics.0.nicknames.1.alias', "José's launch!"));
});

test('confirming a topic makes it the person\'s', function () {
    $user = User::factory()->create();
    app(ContextWriter::class)->record($user, FactOrigin::Inferred, topicScreenOps('El prepago antes del 20.', ['Marta Ruiz']));

    $this->actingAs($user)->post(route('settings.context.topics.confirm'), ['subject' => 'workstream:prepago'])->assertRedirect();

    $event = CommsEvent::query()->where('type', EventType::UserConfigured)->sole();
    $name = CommsContextFact::query()->where('attribute', 'name')->where('status', 'active')->sole();

    expect($event->normalized['origin'])->toBe('user')
        ->and(collect($event->normalized['operations'])->every(fn (array $op): bool => $op['op'] === 'set'))->toBeTrue()
        ->and($name->origin)->toBe(FactOrigin::User)
        ->and($name->text())->toBe('Lanzamiento de primavera')
        ->and(CommsContextFact::query()->where('status', 'proposed')->count())->toBe(0);

    app(Replay::class)->run($user);

    expect(CommsContextFact::query()->where('attribute', 'name')->where('status', 'active')->sole()->text())->toBe('Lanzamiento de primavera')
        ->and(CommsContextFact::query()->where('attribute', 'name')->where('status', 'active')->sole()->origin)->toBe(FactOrigin::User);
});

test('renaming a topic keeps its nicknames', function () {
    $user = User::factory()->create();
    app(ContextWriter::class)->record($user, FactOrigin::Inferred, topicScreenOps('El prepago antes del 20.', ['Marta Ruiz']));

    $this->actingAs($user)->put(route('settings.context.topics.update'), [
        'subject' => 'workstream:prepago',
        'name' => 'Prepago de primavera',
    ])->assertRedirect();

    $event = CommsEvent::query()->where('type', EventType::UserConfigured)->sole();

    expect(collect($event->normalized['operations'])->pluck('op')->all())->toBe(['reject', 'set'])
        ->and(CommsContextFact::query()->where('attribute', 'name')->where('status', 'active')->sole()->text())->toBe('Prepago de primavera')
        ->and(CommsContextFact::query()->where('kind', 'term')->where('status', 'proposed')->count())->toBe(2)
        ->and(CommsTopicSearch::query()->sole()->topic_subject)->toBe('workstream:prepago');
});

test('a nickname can be added and removed', function () {
    $user = User::factory()->create();
    app(ContextWriter::class)->record($user, FactOrigin::Inferred, topicScreenOps('El prepago antes del 20.', ['Marta Ruiz']));

    $this->actingAs($user)->post(route('settings.context.topics.nicknames.store'), [
        'subject' => 'workstream:prepago',
        'alias' => 'Prepago',
    ])->assertRedirect();

    $this->actingAs($user)->post(route('settings.context.topics.nicknames.store'), [
        'subject' => 'workstream:prepago',
        'alias' => 'prepago',
    ])->assertRedirect();

    $added = CommsContextFact::query()->where('kind', 'term')->get()->first(fn (CommsContextFact $fact): bool => ($fact->value['text'] ?? '') === 'Prepago');

    expect($added)->not->toBeNull()
        ->and($added->value['value'])->toBe('workstream:prepago')
        ->and(CommsTopicAlias::query()->where('alias', 'Prepago')->count())->toBe(1)
        ->and(CommsContextFact::query()->where('kind', 'term')->whereIn('status', ['active', 'proposed'])->count())->toBe(3);

    $this->actingAs($user)->delete(route('settings.context.topics.nicknames.destroy'), [
        'subject' => 'workstream:prepago',
        'fact' => $added->id,
    ])->assertRedirect();

    expect(CommsContextFact::query()->whereKey($added->id)->sole()->status)->toBe('rejected')
        ->and(CommsTopicAlias::query()->where('alias', 'Prepago')->count())->toBe(0)
        ->and(CommsTopicAlias::query()->where('alias', 'SPR')->count())->toBe(1);
});

test('merging two topics keeps one and the other name as a nickname', function () {
    $user = User::factory()->create();
    $writer = app(ContextWriter::class);
    $writer->record($user, FactOrigin::Inferred, topicScreenOps('El prepago antes del 20.', ['Marta Ruiz']));
    $writer->record($user, FactOrigin::Inferred, topicScreenOps('El otro hilo.', ['Marta Ruiz'], 'workstream:cliente', 'Cliente', ['Acme']));

    $this->actingAs($user)->post(route('settings.context.topics.merge'), [
        'from' => 'workstream:cliente',
        'into' => 'workstream:prepago',
    ])->assertRedirect();

    $event = CommsEvent::query()->where('type', EventType::UserConfigured)->sole();

    expect($event->normalized['origin'])->toBe('user')
        ->and(CommsContextFact::query()->where('subject', 'workstream:cliente')->whereIn('status', ['active', 'proposed'])->count())->toBe(0)
        ->and(CommsContextFact::query()->where('attribute', 'name')->where('status', 'active')->where('subject', 'workstream:prepago')->count())->toBe(0)
        ->and(CommsTopicAlias::query()->where('topic_subject', 'workstream:prepago')->pluck('alias')->all())->toContain('Cliente');

    $this->actingAs($user)->get(route('settings.context.index', ['tab' => 'topics']))
        ->assertInertia(fn ($page) => $page->has('topics', 1)->where('topics.0.subject', 'workstream:prepago'));
});

test('a rejected topic is not rebuilt by the same inference', function () {
    $user = User::factory()->create();
    $ops = topicScreenOps('El prepago antes del 20.', ['Marta Ruiz']);
    app(ContextWriter::class)->record($user, FactOrigin::Inferred, $ops);

    $this->actingAs($user)->delete(route('settings.context.topics.destroy'), ['subject' => 'workstream:prepago'])->assertRedirect();

    expect(CommsEvent::query()->where('type', EventType::UserConfigured)->sole()->normalized['operations'][0]['op'])->toBe('reject')
        ->and(CommsTopicSearch::query()->count())->toBe(0);

    app(ContextWriter::class)->record($user, FactOrigin::Inferred, $ops);

    expect(CommsContextFact::query()->where('attribute', 'name')->whereIn('status', ['active', 'proposed'])->count())->toBe(0)
        ->and(CommsTopicSearch::query()->count())->toBe(0);
});

test('topic changes belong to the signed-in owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    app(ContextWriter::class)->record($owner, FactOrigin::Inferred, topicScreenOps('El prepago antes del 20.', ['Marta Ruiz']));

    $this->post(route('settings.context.topics.confirm'), ['subject' => 'workstream:prepago'])->assertRedirect(route('login'));

    $this->actingAs($other)->post(route('settings.context.topics.confirm'), ['subject' => 'workstream:prepago'])->assertNotFound();
    $this->actingAs($other)->post(route('settings.context.topics.merge'), [
        'from' => 'workstream:cliente',
        'into' => 'workstream:prepago',
    ])->assertNotFound();

    $this->actingAs($owner)->post(route('settings.context.topics.confirm'), [])->assertSessionHasErrors(['subject']);
    $this->actingAs($owner)->put(route('settings.context.topics.update'), [])->assertSessionHasErrors(['subject', 'name']);
    $this->actingAs($owner)->post(route('settings.context.topics.nicknames.store'), [])->assertSessionHasErrors(['subject', 'alias']);

    expect(CommsEvent::query()->where('type', EventType::UserConfigured)->count())->toBe(0);
});

/**
 * @param  list<string>  $people
 * @param  list<string>  $terms
 * @return list<array<string, mixed>>
 */
function topicScreenOps(string $summary, array $people, string $subject = 'workstream:prepago', string $name = 'Lanzamiento de primavera', array $terms = ['SPR', "José's launch!"]): array
{
    $fact = function (string $kind, string $factSubject, string $attribute, array $value): array {
        return [
            'op' => 'set',
            'kind' => $kind,
            'subject' => $factSubject,
            'attribute' => $attribute,
            'value' => $value,
            'audiences' => [],
            'confidence' => 0.6,
            'refs' => [],
            'status' => 'proposed',
        ];
    };

    $operations = [
        $fact('workstream', $subject, 'name', ['text' => $name]),
        $fact('workstream', $subject, 'summary', ['text' => $summary]),
        $fact('workstream', $subject, 'state', ['text' => 'in progress']),
        $fact('workstream', $subject, 'people', ['value' => $people]),
        $fact('workstream', $subject, 'channels', ['value' => ['slack:C1']]),
    ];

    foreach ($terms as $term) {
        $operations[] = $fact('term', 'term:'.strtolower($term), 'refers_to', ['text' => $term, 'value' => $subject]);
    }

    return $operations;
}
