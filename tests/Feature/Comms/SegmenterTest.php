<?php

use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Linking\Segmenter;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsWindow;
use App\Models\User;
use Carbon\CarbonImmutable;

test('two interleaved conversations stay two segments', function () {
    $user = User::factory()->create();
    $ana = CommsPerson::factory()->for($user)->create(['display_name' => 'Ana Ruiz', 'role' => 'diseño']);
    $luis = CommsPerson::factory()->for($user)->create(['display_name' => 'Luis Gómez']);
    $opened = CarbonImmutable::parse('2026-11-02T10:00:00Z');
    $prepago = segmentMessage($user, $ana, 'El prepago del viernes', $opened);
    $banner = segmentMessage($user, $luis, 'El diseño del banner', $opened->addMinute());
    $friday = segmentMessage($user, $ana, 'Sí, antes del viernes', $opened->addMinutes(2));
    $blue = segmentMessage($user, $luis, 'Usa el azul', $opened->addMinutes(3));
    $window = segmentWindow($user, [$prepago, $banner, $friday, $blue], $opened);

    $fake = segmentScript([[
        'segments' => [
            segmentBody([$prepago, $friday], 'Ana pregunta si el prepago puede quedar antes del viernes.', []),
            segmentBody([$banner, $blue], 'Luis pide el banner en azul.', []),
        ],
    ]]);

    $segments = app(Segmenter::class)->run($window);
    app(Segmenter::class)->run($window);

    expect($segments)->toHaveCount(2)
        ->and($segments[0]->restated)->toBe('Ana pregunta si el prepago puede quedar antes del viernes.')
        ->and($segments[0]->new_message_ids)->toEqual([$prepago->id, $friday->id])
        ->and($segments[0]->unsegmented)->toBeFalse()
        ->and($segments[0]->decision_event_id)->not->toBeNull()
        ->and($segments[1]->new_message_ids)->toEqual([$banner->id, $blue->id])
        ->and(array_intersect($segments[0]->new_message_ids, $segments[1]->new_message_ids))->toBe([])
        ->and(CommsSegment::query()->where('window_id', $window->id)->count())->toBe(2)
        ->and(CommsMessage::query()->whereIn('id', [$prepago->id, $banner->id, $friday->id, $blue->id])->pluck('window_id')->unique()->all())->toEqual([$window->id])
        ->and($fake->calls)->toHaveCount(1)
        ->and($fake->calls[0]['input'])->toContain('CHANNEL', 'PEOPLE', 'WINDOW', 'person:'.$ana->id.' Ana Ruiz diseño', 'El prepago del viernes');
});

test('a question whose referent is earlier in the window keeps the name the model restored', function () {
    $user = User::factory()->create();
    $ana = CommsPerson::factory()->for($user)->create(['display_name' => 'Ana Ruiz']);
    $luis = CommsPerson::factory()->for($user)->create(['display_name' => 'Luis Gómez']);
    $opened = CarbonImmutable::parse('2026-11-02T10:00:00Z');
    $referent = segmentMessage($user, $ana, 'flujo de prepago', $opened->subHours(12));
    for ($i = 11; $i >= 1; $i--) {
        segmentMessage($user, $ana, "nota {$i}", $opened->subHours($i));
    }

    $question = segmentMessage($user, $luis, '¿viste eso?', $opened);
    $window = segmentWindow($user, [$question], $opened->subHours(12));
    $window->message_ids = [$question->id];
    $window->opened_at = $opened;
    $window->save();

    $fake = segmentScript([[
        'segments' => [
            segmentBody([$referent, $question], 'Luis pregunta a Ana si vio el flujo de prepago.', [], [$question]),
        ],
    ]]);

    $segments = app(Segmenter::class)->run($window);

    expect($segments)->toHaveCount(1)
        ->and($segments[0]->restated)->toBe('Luis pregunta a Ana si vio el flujo de prepago.')
        ->and($segments[0]->message_ids)->toEqual([$referent->id, $question->id])
        ->and($segments[0]->new_message_ids)->toEqual([$question->id])
        ->and($fake->calls[0]['input'])->toContain('new="false"', 'flujo de prepago', 'new="true"', '¿viste eso?');
});

test('a reference the window cannot explain is stored unresolved', function () {
    $user = User::factory()->create();
    $ana = CommsPerson::factory()->for($user)->create(['display_name' => 'Ana Ruiz']);
    $opened = CarbonImmutable::parse('2026-11-02T10:00:00Z');
    $message = segmentMessage($user, $ana, 'Lo de ayer con el cliente', $opened);
    $window = segmentWindow($user, [$message], $opened);

    segmentScript([[
        'segments' => [
            segmentBody([$message], 'Ana menciona lo de ayer con el cliente.', ['lo de ayer con el cliente']),
        ],
    ]]);

    $segment = app(Segmenter::class)->run($window)[0];

    expect($segment->restated)->toBe('Ana menciona lo de ayer con el cliente.')
        ->and($segment->unresolved_refs)->toEqual(['lo de ayer con el cliente'])
        ->and($segment->unsegmented)->toBeFalse();
});

test('a dropped new message makes the output invalid', function () {
    $user = User::factory()->create();
    $ana = CommsPerson::factory()->for($user)->create();
    $opened = CarbonImmutable::parse('2026-11-02T10:00:00Z');
    $first = segmentMessage($user, $ana, 'El prepago', $opened);
    $second = segmentMessage($user, $ana, 'Y el banner', $opened->addMinute());
    $window = segmentWindow($user, [$first, $second], $opened);

    segmentScript([[
        'segments' => [
            segmentBody([$first], 'Solo el prepago.', []),
        ],
    ]]);

    $segment = app(Segmenter::class)->run($window)[0];

    expect(CommsSegment::query()->where('window_id', $window->id)->count())->toBe(1)
        ->and($segment->unsegmented)->toBeTrue()
        ->and($segment->restated)->toBe('')
        ->and($segment->message_ids)->toEqual([$first->id, $second->id])
        ->and($segment->new_message_ids)->toEqual([$first->id, $second->id]);
});

test('an id that was not in the window makes the output invalid', function () {
    $user = User::factory()->create();
    $ana = CommsPerson::factory()->for($user)->create();
    $opened = CarbonImmutable::parse('2026-11-02T10:00:00Z');
    $message = segmentMessage($user, $ana, 'El prepago', $opened);
    $window = segmentWindow($user, [$message], $opened);

    segmentScript([[
        'segments' => [[
            'message_ids' => ['m'.$message->id, 'm999999'],
            'new_message_ids' => ['m'.$message->id],
            'participants' => ['person:'.$ana->id],
            'restated' => 'Ana habla del prepago.',
            'unresolved_refs' => [],
        ]],
    ]]);

    expect(app(Segmenter::class)->run($window)[0]->unsegmented)->toBeTrue();
});

test('a segment with no new message is discarded', function () {
    $user = User::factory()->create();
    $ana = CommsPerson::factory()->for($user)->create();
    $opened = CarbonImmutable::parse('2026-11-02T10:00:00Z');
    $earlier = segmentMessage($user, $ana, 'El flujo', $opened->subHour());
    $current = segmentMessage($user, $ana, 'Sigo con ello', $opened);
    $window = segmentWindow($user, [$current], $opened);

    segmentScript([[
        'segments' => [
            segmentBody([$earlier], 'Solo contexto.', [], []),
            segmentBody([$earlier, $current], 'Ana sigue con el flujo.', [], [$current]),
        ],
    ]]);

    $segments = app(Segmenter::class)->run($window);

    expect($segments)->toHaveCount(1)
        ->and($segments[0]->restated)->toBe('Ana sigue con el flujo.')
        ->and($segments[0]->new_message_ids)->toEqual([$current->id])
        ->and($segments[0]->unsegmented)->toBeFalse();
});

test('a model failure gives one unsegmented segment', function () {
    $user = User::factory()->create();
    $ana = CommsPerson::factory()->for($user)->create();
    $opened = CarbonImmutable::parse('2026-11-02T10:00:00Z');
    $message = segmentMessage($user, $ana, 'El prepago', $opened);
    $window = segmentWindow($user, [$message], $opened);
    app()->instance(CompletesTasks::class, new FakeCompleter);

    $segment = app(Segmenter::class)->run($window)[0];

    expect($segment->unsegmented)->toBeTrue()
        ->and($segment->restated)->toBe('')
        ->and($segment->message_ids)->toEqual([$message->id])
        ->and(CommsEvent::query()->find($segment->decision_event_id)?->type)->toBe(EventType::Question);
});

/**
 * @param  list<CommsMessage>  $messages
 */
function segmentWindow(User $user, array $messages, CarbonImmutable $opened): CommsWindow
{
    return CommsWindow::query()->create([
        'user_id' => $user->id,
        'source' => 'slack',
        'group_ref' => 'C1',
        'kind' => 'channel',
        'message_ids' => array_map(fn (CommsMessage $message): int => $message->id, $messages),
        'opened_at' => $opened,
        'last_message_at' => $messages[array_key_last($messages)]->occurred_at,
        'state' => 'settled',
    ]);
}

function segmentMessage(User $user, CommsPerson $author, string $text, CarbonImmutable $at): CommsMessage
{
    return CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $author->id,
        'event_id' => CommsEvent::factory()->create([
            'user_id' => $user->id,
            'processed_at' => now(),
        ])->id,
        'source' => Source::Slack,
        'channel_ref' => 'C1',
        'thread_ref' => null,
        'text' => $text,
        'direction' => 'in',
        'occurred_at' => $at,
        'external_id' => 'T:C1:'.$at->getTimestamp().fake()->unique()->numerify('####'),
    ]);
}

/**
 * @param  list<CommsMessage>  $messages
 * @param  list<string>  $refs
 * @param  list<CommsMessage>|null  $new
 * @return array<string, mixed>
 */
function segmentBody(array $messages, string $restated, array $refs, ?array $new = null): array
{
    $new ??= $messages;

    return [
        'message_ids' => array_map(fn (CommsMessage $message): string => 'm'.$message->id, $messages),
        'new_message_ids' => array_map(fn (CommsMessage $message): string => 'm'.$message->id, $new),
        'participants' => array_values(array_unique(array_map(
            fn (CommsMessage $message): string => 'person:'.$message->author_person_id,
            $messages,
        ))),
        'restated' => $restated,
        'unresolved_refs' => $refs,
    ];
}

/**
 * @param  list<array<string, mixed>>  $bodies
 */
function segmentScript(array $bodies): FakeCompleter
{
    $fake = new FakeCompleter;
    $fake->script = array_map(
        fn (array $body): ModelCompletion => new ModelCompletion($body, 'scripted'),
        $bodies,
    );
    app()->instance(CompletesTasks::class, $fake);

    return $fake;
}
