<?php

use App\Comms\Backfill\Passes;
use App\Comms\Context\FactOrigin;
use App\Comms\Events\EventType;
use App\Comms\Events\Replay;
use App\Comms\Events\Source;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Comms\Topics\TopicBuilder;
use App\Models\Comms\CommsContextFact;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

test('one channel week becomes a proposed topic and its nicknames', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->create([
        'user_id' => $user->id,
        'display_name' => 'Marta Ruiz',
        'role' => 'Producto',
    ]);
    $first = topicMessage($user, $marta, 'C1', '2026-11-02T10:00:00Z', 'El lanzamiento de primavera');
    $second = topicMessage($user, $marta, 'C1', '2026-11-03T11:00:00Z', 'SPR sigue abierto');
    topicMessage($user, $marta, 'C1', '2026-11-03T12:00:00Z', 'Borrado', deleted: true);
    $topic = topicShape('Lanzamiento de primavera', ['person:'.$marta->id], ['slack:C1'], ['m'.$first->id, 'm'.$second->id], ['SPR', 'lanzamiento']);
    $fake = scriptedTopics([$topic], [$topic]);

    $counts = app(Passes::class)->run($user, 'topics');

    $input = $fake->calls[0]['input'];
    $peopleAt = strpos($input, 'PEOPLE');
    $conversationsAt = strpos($input, 'CONVERSATIONS');
    $knownAt = strpos($input, 'KNOWN TOPICS');

    expect($counts)->toBe(['topics' => 1, 'terms' => 2])
        ->and($fake->calls)->toHaveCount(2)
        ->and($peopleAt)->toBeInt()
        ->and($conversationsAt)->toBeGreaterThan($peopleAt)
        ->and($knownAt)->toBeGreaterThan($conversationsAt)
        ->and($input)->toContain('person:'.$marta->id.' Marta Ruiz Producto')
        ->and($input)->toContain('slack:C1 2026-W45')
        ->and($input)->toContain('<source id="m'.$first->id.'" author="person:'.$marta->id.'" at="2026-11-02T10:00:00+00:00">El lanzamiento de primavera</source>')
        ->and($input)->toContain('<source id="m'.$second->id.'"')
        ->and($input)->not->toContain('Borrado')
        ->and(substr_count($input, '<source '))->toBe(2);

    $facts = CommsContextFact::query()->whereBelongsTo($user)->orderBy('id')->get();
    $workstream = $facts->where('kind', 'workstream');

    expect($workstream->pluck('attribute')->sort()->values()->all())->toBe(['channels', 'name', 'people', 'state', 'summary'])
        ->and($workstream->every(fn (CommsContextFact $fact): bool => $fact->subject === 'workstream:lanzamiento-de-primavera'
            && $fact->status === 'proposed'
            && $fact->origin === FactOrigin::Inferred
            && $fact->refs === ['m'.$first->id, 'm'.$second->id]))->toBeTrue()
        ->and($workstream->firstWhere('attribute', 'name')->text())->toBe('Lanzamiento de primavera')
        ->and($workstream->firstWhere('attribute', 'summary')->text())->toBe('El prepago antes del 20.')
        ->and($workstream->firstWhere('attribute', 'state')->text())->toBe('in progress')
        ->and($workstream->firstWhere('attribute', 'people')->value)->toEqual(['value' => ['person:'.$marta->id]])
        ->and($workstream->firstWhere('attribute', 'channels')->value)->toEqual(['value' => ['slack:C1']])
        ->and($facts->where('kind', 'term')->pluck('subject')->sort()->values()->all())->toBe(['term:lanzamiento', 'term:spr'])
        ->and($facts->where('kind', 'term')->every(fn (CommsContextFact $fact): bool => $fact->attribute === 'refers_to'
            && $fact->status === 'proposed'
            && ($fact->value['value'] ?? null) === 'workstream:lanzamiento-de-primavera'))->toBeTrue()
        ->and(CommsEvent::query()->where('type', EventType::ContextInferred)->where('source', Source::Model)->count())->toBe(1);

    app(TopicBuilder::class)->run($user);

    expect($fake->calls)->toHaveCount(2)
        ->and(CommsContextFact::query()->whereBelongsTo($user)->count())->toBe(7);

    app(Replay::class)->run($user);

    expect(CommsContextFact::query()->whereBelongsTo($user)->where('attribute', 'name')->sole()->text())->toBe('Lanzamiento de primavera')
        ->and(CommsContextFact::query()->whereBelongsTo($user)->where('attribute', 'name')->sole()->status)->toBe('proposed');
});

test('two channels in one batch collapse to the merged topic', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->create(['user_id' => $user->id, 'display_name' => 'Marta']);
    $pablo = CommsPerson::factory()->create(['user_id' => $user->id, 'display_name' => 'Pablo']);
    $slack = topicMessage($user, $marta, 'C1', '2026-11-02T10:00:00Z', 'Hablemos del prepago');
    $mail = topicMessage($user, $pablo, 'inbox', '2026-11-08T10:00:00Z', 'El cliente espera', source: Source::Gmail);
    $fromSlack = topicShape('Prepago', ['person:'.$marta->id], ['slack:C1'], ['m'.$slack->id]);
    $fromMail = topicShape('Cliente', ['person:'.$pablo->id], ['gmail:inbox'], ['m'.$mail->id]);
    $merged = topicShape('Prepago', ['person:'.$marta->id, 'person:'.$pablo->id], ['gmail:inbox', 'slack:C1'], ['m'.$slack->id, 'm'.$mail->id], ['prepago']);
    $fake = scriptedTopics([$fromSlack, $fromMail], [$merged]);

    app(TopicBuilder::class)->run($user);

    expect($fake->calls)->toHaveCount(2)
        ->and($fake->calls[0]['input'])->toContain('slack:C1 2026-W45')
        ->and($fake->calls[0]['input'])->toContain('gmail:inbox 2026-W45')
        ->and(CommsContextFact::query()->where('kind', 'workstream')->where('attribute', 'name')->sole()->text())->toBe('Prepago')
        ->and(CommsContextFact::query()->where('kind', 'workstream')->pluck('subject')->unique()->values()->all())->toBe(['workstream:prepago']);
});

test('a batch that fills up carries its topics into the next batch', function () {
    Config::set('comms.backfill.topic_batch_chars', 1);
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->create(['user_id' => $user->id, 'display_name' => 'Marta']);
    $first = topicMessage($user, $marta, 'C1', '2026-11-02T10:00:00Z', 'Uno');
    $second = topicMessage($user, $marta, 'C1', '2026-11-02T11:00:00Z', 'Dos');
    $kept = topicShape('Prepago', ['person:'.$marta->id], ['slack:C1'], ['m'.$first->id, 'm'.$second->id]);
    $fake = scriptedTopics(
        [topicShape('Prepago', ['person:'.$marta->id], ['slack:C1'], ['m'.$first->id])],
        [topicShape('Otro', ['person:'.$marta->id], ['slack:C1'], ['m'.$second->id])],
        [$kept],
    );

    app(TopicBuilder::class)->run($user);

    expect($fake->calls)->toHaveCount(3)
        ->and(substr_count($fake->calls[0]['input'], '<source '))->toBe(1)
        ->and(substr_count($fake->calls[1]['input'], '<source '))->toBe(1)
        ->and($fake->calls[1]['input'])->toContain('name: Prepago')
        ->and($fake->calls[2]['input'])->toContain('name: Prepago')
        ->and($fake->calls[2]['input'])->toContain('name: Otro')
        ->and(CommsContextFact::query()->where('attribute', 'name')->sole()->text())->toBe('Prepago');
});

test('a topic that cites a message outside the batch is dropped', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->create(['user_id' => $user->id, 'display_name' => 'Marta']);
    topicMessage($user, $marta, 'C1', '2026-11-02T10:00:00Z', 'Hola');
    $fake = scriptedTopics([
        topicShape('Inventado', ['person:'.$marta->id], ['slack:C1'], ['m999999']),
    ]);

    expect(app(TopicBuilder::class)->run($user))->toBe(['topics' => 0, 'terms' => 0])
        ->and($fake->calls)->toHaveCount(1)
        ->and(CommsContextFact::query()->count())->toBe(0);
});

test('a merge that keeps no topic writes nothing', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->create(['user_id' => $user->id, 'display_name' => 'Marta']);
    $message = topicMessage($user, $marta, 'C1', '2026-11-02T10:00:00Z', '¿Comemos?');
    scriptedTopics(
        [topicShape('Comida', ['person:'.$marta->id], ['slack:C1'], ['m'.$message->id])],
        [],
    );

    expect(app(TopicBuilder::class)->run($user))->toBe(['topics' => 0, 'terms' => 0])
        ->and(CommsContextFact::query()->count())->toBe(0);
});

test('no conversations means the model is not asked', function () {
    $user = User::factory()->create();
    $fake = scriptedTopics([]);

    expect(app(TopicBuilder::class)->run($user))->toBe(['topics' => 0, 'terms' => 0])
        ->and($fake->calls)->toBeEmpty();
});

test('a failed topic model writes nothing', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->create(['user_id' => $user->id, 'display_name' => 'Marta']);
    topicMessage($user, $marta, 'C1', '2026-11-02T10:00:00Z', 'Hola');
    $fake = new FakeCompleter;
    app()->instance(CompletesTasks::class, $fake);

    expect(app(TopicBuilder::class)->run($user))->toBe(['topics' => 0, 'terms' => 0])
        ->and($fake->calls)->not->toBeEmpty()
        ->and(CommsContextFact::query()->count())->toBe(0);
});

function topicMessage(
    User $user,
    CommsPerson $author,
    string $channel,
    string $at,
    string $text,
    Source $source = Source::Slack,
    bool $deleted = false,
): CommsMessage {
    $when = CarbonImmutable::parse($at);
    $event = CommsEvent::factory()->create([
        'user_id' => $user->id,
        'source' => $source,
        'occurred_at' => $when,
        'processed_at' => $when,
    ]);

    return CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $author->id,
        'event_id' => $event->id,
        'source' => $source,
        'channel_ref' => $channel,
        'text' => $text,
        'occurred_at' => $when,
        'deleted_at' => $deleted ? $when : null,
    ]);
}

/**
 * @param  list<string>  $people
 * @param  list<string>  $channels
 * @param  list<string>  $examples
 * @param  list<string>  $terms
 * @return array<string, mixed>
 */
function topicShape(string $name, array $people, array $channels, array $examples, array $terms = ['SPR']): array
{
    return [
        'name' => $name,
        'summary' => 'El prepago antes del 20.',
        'terms' => $terms,
        'people' => $people,
        'channels' => $channels,
        'state' => 'in progress',
        'example_message_ids' => $examples,
    ];
}

/**
 * @param  list<array<string, mixed>>  ...$batches
 */
function scriptedTopics(array ...$batches): FakeCompleter
{
    $fake = new FakeCompleter;
    $fake->script = array_map(
        fn (array $topics): ModelCompletion => new ModelCompletion(['topics' => $topics], 'scripted'),
        $batches,
    );
    app()->instance(CompletesTasks::class, $fake);

    return $fake;
}
