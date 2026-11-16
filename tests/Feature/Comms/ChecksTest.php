<?php

use App\Comms\Events\EventType;
use App\Comms\Linking\AgreedOperation;
use App\Comms\Linking\AgreementStatus;
use App\Comms\Linking\Checks;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsPreference;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsWindow;
use App\Models\User;
use Carbon\CarbonImmutable;

test('a quote that is not in the segment is rejected and the one that is stays', function () {
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $message = checksMessage($user, $author, "te lo\nmando", CarbonImmutable::parse('2026-03-13 15:00:00', 'UTC'));
    $segment = checksSegment($user, [$message]);
    $kept = checksOp('open', 'te lo mando');
    $dropped = checksOp('open', 'no está aquí');

    $passed = app(Checks::class)->run($segment, [$kept, $dropped]);
    $logged = CommsEvent::query()->where('type', EventType::StateApplied)->sole();

    expect($passed)->toHaveCount(1)
        ->and($passed[0]->operation['quote'])->toBe('te lo mando')
        ->and($logged->normalized['segment_id'])->toBe($segment->id)
        ->and($logged->normalized['results'])->toEqual([
            ['op' => 'open', 'status' => 'rejected', 'reason' => 'quote', 'quote' => 'no está aquí'],
        ]);
});

test('an unknown person is rejected and unclear is allowed', function () {
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $message = checksMessage($user, $author, '¿Me lo confirmas?', CarbonImmutable::parse('2026-03-13 15:00:00', 'UTC'));
    $segment = checksSegment($user, [$message]);
    $kept = checksOp('open', '¿Me lo confirmas?', ['responders' => ['unclear']]);
    $dropped = checksOp('open', '¿Me lo confirmas?', ['responders' => ['person:999999']]);

    $passed = app(Checks::class)->run($segment, [$kept, $dropped]);

    expect($passed)->toHaveCount(1)
        ->and($passed[0]->operation['responders'])->toBe(['unclear'])
        ->and(CommsEvent::query()->where('type', EventType::StateApplied)->sole()->normalized['results'][0]['reason'])->toBe('person');
});

test('a deadline before the newest message is rejected', function () {
    $user = User::factory()->create();
    CommsPreference::query()->create([
        'user_id' => $user->id,
        'timezone' => 'America/Los_Angeles',
        'locale' => 'es',
        'work_start' => '09:00',
        'work_end' => '18:00',
        'work_days' => [1, 2, 3, 4, 5],
    ]);
    $author = CommsPerson::factory()->for($user)->create();
    $message = checksMessage($user, $author, 'Antes del viernes', CarbonImmutable::parse('2026-03-13 05:00:00', 'UTC'));
    $segment = checksSegment($user, [$message]);
    $sameDay = checksOp('open', 'Antes del viernes', ['deadline' => ['date' => '2026-03-12', 'text' => 'el viernes']]);
    $early = checksOp('open', 'Antes del viernes', ['deadline' => ['date' => '2026-03-11', 'text' => 'ayer']]);
    $words = checksOp('open', 'Antes del viernes', ['deadline' => ['date' => 'el viernes', 'text' => 'el viernes']]);

    $passed = app(Checks::class)->run($segment, [$sameDay, $early, $words]);
    $reasons = CommsEvent::query()->where('type', EventType::StateApplied)->sole()->normalized['results'];

    expect($passed)->toHaveCount(1)
        ->and($passed[0]->operation['deadline']['date'])->toBe('2026-03-12')
        ->and(array_column($reasons, 'reason'))->toBe(['deadline', 'deadline']);
});

test('a settle or reopen the state machine would refuse is rejected', function () {
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $at = CarbonImmutable::parse('2026-03-13 15:00:00', 'UTC');
    $message = checksMessage($user, $author, 'Ya está el documento', $at);
    $open = checksIntent($user, 'open', null);
    $settled = checksIntent($user, 'settled', $at);
    $stale = checksIntent($user, 'settled', $at->subDays(20));
    $segment = checksSegment($user, [$message]);
    $quote = 'Ya está el documento';

    $passed = app(Checks::class)->run($segment, [
        checksOp('settle', $quote, ['intent_id' => (string) $open->id, 'settle_reason' => 'decision']),
        checksOp('settle', $quote, ['intent_id' => (string) $settled->id, 'settle_reason' => 'decision']),
        checksOp('reopen', $quote, ['intent_id' => (string) $settled->id]),
        checksOp('reopen', $quote, ['intent_id' => (string) $open->id]),
        checksOp('reopen', $quote, ['intent_id' => (string) $stale->id]),
        checksOp('attach', $quote, ['intent_id' => '999999']),
    ]);
    $reasons = array_column(
        CommsEvent::query()->where('type', EventType::StateApplied)->sole()->normalized['results'],
        'reason',
    );

    expect(array_map(fn (AgreedOperation $operation): string => $operation->op(), $passed))->toBe(['settle', 'reopen'])
        ->and($passed[0]->operation['intent_id'])->toBe((string) $open->id)
        ->and($passed[1]->operation['intent_id'])->toBe((string) $settled->id)
        ->and($reasons)->toBe(['state', 'state', 'window', 'intent']);
});

test('a thumbs up cannot settle an intent', function () {
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $message = checksMessage($user, $author, '👍', CarbonImmutable::parse('2026-03-13 15:00:00', 'UTC'));
    $open = checksIntent($user, 'open', null);
    $segment = checksSegment($user, [$message]);

    $passed = app(Checks::class)->run($segment, [
        checksOp('settle', '👍', ['intent_id' => (string) $open->id, 'settle_reason' => 'decision']),
    ]);

    expect($passed)->toBe([])
        ->and(CommsEvent::query()->where('type', EventType::StateApplied)->sole()->normalized['results'][0]['reason'])->toBe('acknowledgement');
});

test('operations that pass are not logged as applied', function () {
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $message = checksMessage($user, $author, 'Te lo mando el jueves', CarbonImmutable::parse('2026-03-13 15:00:00', 'UTC'));
    $segment = checksSegment($user, [$message]);

    $passed = app(Checks::class)->run($segment, [checksOp('open', 'Te lo mando el jueves')]);

    expect($passed)->toHaveCount(1)
        ->and(CommsEvent::query()->where('type', EventType::StateApplied)->count())->toBe(0);
});

/**
 * @param  array<string, mixed>  $extra
 */
function checksOp(string $op, string $quote, array $extra = []): AgreedOperation
{
    return new AgreedOperation([
        'op' => $op,
        'quote' => $quote,
        ...$extra,
    ], AgreementStatus::Agreed);
}

/**
 * @param  list<CommsMessage>  $messages
 */
function checksSegment(User $user, array $messages): CommsSegment
{
    $window = CommsWindow::query()->create([
        'user_id' => $user->id,
        'source' => 'slack',
        'group_ref' => 'C1',
        'kind' => 'channel',
        'message_ids' => array_map(fn (CommsMessage $message): int => $message->id, $messages),
        'opened_at' => now(),
        'last_message_at' => now(),
        'state' => 'settled',
    ]);

    return CommsSegment::query()->create([
        'user_id' => $user->id,
        'window_id' => $window->id,
        'message_ids' => array_map(fn (CommsMessage $message): int => $message->id, $messages),
        'new_message_ids' => array_map(fn (CommsMessage $message): int => $message->id, $messages),
        'participants' => [],
        'restated' => 'Un tramo',
        'unresolved_refs' => [],
    ]);
}

function checksMessage(User $user, CommsPerson $author, string $text, CarbonImmutable $at): CommsMessage
{
    return CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $author->id,
        'event_id' => CommsEvent::factory()->create([
            'user_id' => $user->id,
            'processed_at' => now(),
        ])->id,
        'text' => $text,
        'direction' => 'in',
        'occurred_at' => $at,
    ]);
}

function checksIntent(User $user, string $state, ?CarbonImmutable $settledAt): CommsIntent
{
    return CommsIntent::factory()->create([
        'user_id' => $user->id,
        'what' => 'Enviar el documento',
        'state' => $state,
        'settled_at' => $settledAt,
    ]);
}
