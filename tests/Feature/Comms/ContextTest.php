<?php

use App\Comms\Context\ContextEngine;
use App\Comms\Context\ContextWriter;
use App\Comms\Context\FactOrigin;
use App\Comms\Events\Replay;
use App\Models\Comms\CommsContextFact;
use App\Models\KnowledgeDocument;
use App\Models\User;

test('a fact the person stated wins over one the model guessed', function () {
    $user = User::factory()->create();
    $writer = app(ContextWriter::class);
    $operation = fn (string $relation): array => [[
        'op' => 'set',
        'kind' => 'person',
        'subject' => 'person:marta',
        'attribute' => 'relation',
        'value' => ['value' => $relation],
        'audiences' => [],
    ]];

    $writer->record($user, FactOrigin::User, $operation('client'));
    $writer->record($user, FactOrigin::Inferred, $operation('boss'));

    expect(CommsContextFact::query()->where('status', 'active')->sole()->text())->toBe('client')
        ->and(CommsContextFact::query()->where('status', 'proposed')->sole()->text())->toBe('boss');
});

test('a rejected fact is not brought back by a later guess', function () {
    $user = User::factory()->create();
    $writer = app(ContextWriter::class);
    $operation = [[
        'op' => 'set',
        'kind' => 'rule',
        'subject' => 'user',
        'attribute' => 'rule',
        'value' => ['text' => 'Los avisos de facturación no importan.'],
        'audiences' => [],
    ]];

    $writer->record($user, FactOrigin::Inferred, $operation);
    $writer->record($user, FactOrigin::User, [[
        'op' => 'reject',
        'kind' => 'rule',
        'subject' => 'user',
        'attribute' => 'rule',
        'value' => ['text' => 'Los avisos de facturación no importan.'],
    ]]);
    $writer->record($user, FactOrigin::Inferred, $operation);

    expect(CommsContextFact::query()->where('status', 'active')->count())->toBe(0);
});

test('replay rebuilds the facts from the log', function () {
    $user = User::factory()->create();

    app(ContextWriter::class)->record($user, FactOrigin::User, [[
        'op' => 'set',
        'kind' => 'preference',
        'subject' => 'user',
        'attribute' => 'locale',
        'value' => ['value' => 'es'],
        'audiences' => [],
    ]]);

    app(Replay::class)->run($user);

    expect(CommsContextFact::query()->whereBelongsTo($user)->where('status', 'active')->sole()->text())->toBe('es');
});

test('search reads the knowledge corpus', function () {
    $user = User::factory()->create();
    KnowledgeDocument::factory()->for($user)->create([
        'title' => 'Flujo de prepago',
        'summary' => 'El flujo de prepago cobra antes de la reserva.',
    ]);

    $hits = app(ContextEngine::class)->search($user, 'cómo va el prepago');

    expect($hits)->not->toBeEmpty()
        ->and($hits[0]['document']->title)->toBe('Flujo de prepago');
});
