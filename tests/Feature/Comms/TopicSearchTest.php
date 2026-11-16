<?php

use App\Comms\Context\ContextWriter;
use App\Comms\Context\FactOrigin;
use App\Comms\Events\Replay;
use App\Comms\Models\Embeddings;
use App\Models\Comms\CommsContextFact;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsTopicAlias;
use App\Models\Comms\CommsTopicSearch;
use App\Models\User;

test('a proposed topic is searchable by its name, summary, nicknames and people', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->create(['user_id' => $user->id, 'display_name' => 'Marta Ruiz']);

    app(ContextWriter::class)->record($user, FactOrigin::Inferred, topicSearchOps('El prepago antes del 20.', ['person:'.$marta->id]));

    $document = implode("\n", [
        'Lanzamiento de primavera',
        'El prepago antes del 20.',
        'SPR',
        "José's launch!",
        'Marta Ruiz',
    ]);
    $search = CommsTopicSearch::query()->whereBelongsTo($user)->sole();

    expect(CommsContextFact::query()->where('attribute', 'name')->sole()->status)->toBe('proposed')
        ->and($search->topic_subject)->toBe('workstream:prepago')
        ->and($search->document)->toBe($document)
        ->and($search->embedding_hash)->toBe(sha1($document))
        ->and(CommsTopicAlias::query()->whereBelongsTo($user)->orderBy('alias')->get()->map(fn (CommsTopicAlias $alias): array => [
            'topic_subject' => $alias->topic_subject,
            'alias' => $alias->alias,
            'alias_normalized' => $alias->alias_normalized,
        ])->all())->toEqual([
            ['topic_subject' => 'workstream:prepago', 'alias' => "José's launch!", 'alias_normalized' => 'jose s launch'],
            ['topic_subject' => 'workstream:prepago', 'alias' => 'SPR', 'alias_normalized' => 'spr'],
        ]);

    app(ContextWriter::class)->record($user, FactOrigin::Inferred, topicSearchOps('El prepago antes del 20.', ['person:'.$marta->id]));

    expect(CommsTopicSearch::query()->whereBelongsTo($user)->count())->toBe(1)
        ->and(CommsTopicAlias::query()->whereBelongsTo($user)->count())->toBe(2);
});

test('a new summary replaces the search document and its embedding', function () {
    $user = User::factory()->create();

    app(ContextWriter::class)->record($user, FactOrigin::Inferred, topicSearchOps('Primera versión.', ['Marta Ruiz']));
    app(ContextWriter::class)->record($user, FactOrigin::Inferred, topicSearchOps('Segunda versión.', ['Marta Ruiz']));

    $search = CommsTopicSearch::query()->whereBelongsTo($user)->sole();

    expect($search->document)->toContain('Segunda versión.')
        ->and($search->document)->not->toContain('Primera versión.')
        ->and($search->embedding_hash)->toBe(sha1($search->document));
});

test('rejecting the topic removes it from search', function () {
    $user = User::factory()->create();
    $writer = app(ContextWriter::class);
    $writer->record($user, FactOrigin::Inferred, topicSearchOps('El prepago antes del 20.', ['Marta Ruiz']));
    $ids = CommsContextFact::query()->whereBelongsTo($user)->whereIn('status', ['active', 'proposed'])->pluck('id');

    foreach ($ids as $id) {
        $writer->reject($user, $id);
    }

    expect(CommsTopicSearch::query()->whereBelongsTo($user)->count())->toBe(0)
        ->and(CommsTopicAlias::query()->whereBelongsTo($user)->count())->toBe(0);
});

test('replay rebuilds the search row and the nicknames', function () {
    $user = User::factory()->create();

    app(ContextWriter::class)->record($user, FactOrigin::Inferred, topicSearchOps('El prepago antes del 20.', ['Marta Ruiz']));
    $document = CommsTopicSearch::query()->whereBelongsTo($user)->sole()->document;
    $aliases = CommsTopicAlias::query()->whereBelongsTo($user)->orderBy('alias')->get()->map(fn (CommsTopicAlias $alias): array => [
        'alias' => $alias->alias,
        'alias_normalized' => $alias->alias_normalized,
    ])->all();

    app(Replay::class)->run($user);

    expect(CommsTopicSearch::query()->whereBelongsTo($user)->sole()->document)->toBe($document)
        ->and(CommsTopicSearch::query()->whereBelongsTo($user)->sole()->embedding_hash)->toBe(sha1($document))
        ->and(CommsTopicAlias::query()->whereBelongsTo($user)->orderBy('alias')->get()->map(fn (CommsTopicAlias $alias): array => [
            'alias' => $alias->alias,
            'alias_normalized' => $alias->alias_normalized,
        ])->all())->toEqual($aliases);
});

test('a missing embedding still keeps the document', function () {
    app()->instance(Embeddings::class, new class extends Embeddings
    {
        public function vector(string $text): ?array
        {
            return null;
        }
    });
    $user = User::factory()->create();

    app(ContextWriter::class)->record($user, FactOrigin::Inferred, topicSearchOps('El prepago antes del 20.', ['Marta Ruiz']));

    $search = CommsTopicSearch::query()->whereBelongsTo($user)->sole();

    expect($search->document)->toContain('Lanzamiento de primavera')
        ->and($search->embedding_hash)->toBeNull();
});

test('a rule is not a topic', function () {
    $user = User::factory()->create();

    app(ContextWriter::class)->record($user, FactOrigin::Inferred, [[
        'op' => 'set',
        'kind' => 'rule',
        'subject' => 'user',
        'attribute' => 'rule',
        'value' => ['text' => 'Nada de emojis.'],
        'audiences' => [],
        'status' => 'proposed',
    ]]);

    expect(CommsTopicSearch::query()->count())->toBe(0)
        ->and(CommsTopicAlias::query()->count())->toBe(0);
});

/**
 * @param  list<string>  $people
 * @return list<array<string, mixed>>
 */
function topicSearchOps(string $summary, array $people): array
{
    $fact = function (string $kind, string $subject, string $attribute, array $value): array {
        return [
            'op' => 'set',
            'kind' => $kind,
            'subject' => $subject,
            'attribute' => $attribute,
            'value' => $value,
            'audiences' => [],
            'confidence' => 0.6,
            'refs' => [],
            'status' => 'proposed',
        ];
    };

    return [
        $fact('workstream', 'workstream:prepago', 'name', ['text' => 'Lanzamiento de primavera']),
        $fact('workstream', 'workstream:prepago', 'summary', ['text' => $summary]),
        $fact('workstream', 'workstream:prepago', 'people', ['value' => $people]),
        $fact('term', 'term:spr', 'refers_to', ['text' => 'SPR', 'value' => 'workstream:prepago']),
        $fact('term', 'term:joses-launch', 'refers_to', ['text' => "José's launch!", 'value' => 'workstream:prepago']),
    ];
}
