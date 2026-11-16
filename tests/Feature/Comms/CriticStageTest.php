<?php

use App\Comms\Events\EventType;
use App\Comms\Linking\AgreedOperation;
use App\Comms\Linking\AgreementStatus;
use App\Comms\Linking\CriticStage;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Comms\People\Relation;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsTopicSearch;
use App\Models\Comms\CommsWindow;
use App\Models\User;
use Carbon\CarbonImmutable;

test('a coffee invitation is refuted from the raw message', function () {
    $user = User::factory()->create();
    $self = CommsPerson::factory()->for($user)->create([
        'display_name' => 'John',
        'relation' => Relation::Self,
    ]);
    $text = 'we should grab a coffee sometime';
    $message = criticMessage($user, $self, $text, CarbonImmutable::parse('2026-03-13 15:00:00', 'UTC'));
    $segment = criticSegment($user, [$message], 'RESUMEN QUE NO DEBE VERSE');
    $operation = criticOp('open', $text);
    $fake = criticScript([criticAnswer('real_ask', $text)]);

    $reviews = app(CriticStage::class)->run($segment, [$operation], null);

    expect($reviews)->toHaveCount(1)
        ->and($reviews[0]->refuted)->toBeTrue()
        ->and($reviews[0]->operation->status)->toBe(AgreementStatus::Agreed)
        ->and($reviews[0]->operation->operation['quote'])->toBe($text)
        ->and($fake->calls)->toHaveCount(1)
        ->and($fake->calls[0]['provider'])->toBe('gemini')
        ->and($fake->calls[0]['system'])->toContain('Do not propose a replacement operation.')
        ->and($fake->calls[0]['input'])->toContain('<source id="m'.$message->id.'"')
        ->and($fake->calls[0]['input'])->toContain($text)
        ->and($fake->calls[0]['input'])->toContain('person:'.$self->id.' John')
        ->and($fake->calls[0]['input'])->not->toContain('RESUMEN QUE NO DEBE VERSE')
        ->and(CommsEvent::query()->where('type', EventType::StateApplied)->count())->toBe(0);
});

test('an ask addressed to someone else is refuted', function () {
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $text = 'Dani, ¿puedes mirar el flujo?';
    $message = criticMessage($user, $author, $text, CarbonImmutable::parse('2026-03-13 15:00:00', 'UTC'));
    $segment = criticSegment($user, [$message], 'Se lo pide a Dani');
    criticScript([criticAnswer('addressed_to_user', $text)]);

    $reviews = app(CriticStage::class)->run($segment, [criticOp('open', $text)], null);

    expect($reviews[0]->refuted)->toBeTrue()
        ->and($reviews[0]->operation->op())->toBe('open');
});

test('a refutation whose quote is not in the messages is ignored', function () {
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $message = criticMessage($user, $author, '¿Me confirmas la fecha?', CarbonImmutable::parse('2026-03-13 15:00:00', 'UTC'));
    $segment = criticSegment($user, [$message], 'Pide una fecha');
    criticScript([criticAnswer('addressed_to_user', 'esto no se dijo')]);

    $reviews = app(CriticStage::class)->run($segment, [criticOp('open', '¿Me confirmas la fecha?')], null);
    $logged = CommsEvent::query()->where('type', EventType::StateApplied)->sole();

    expect($reviews[0]->refuted)->toBeFalse()
        ->and($logged->normalized['segment_id'])->toBe($segment->id)
        ->and($logged->normalized['results'])->toEqual([
            ['op' => 'open', 'status' => 'rejected', 'reason' => 'ignored', 'quote' => 'esto no se dijo', 'question' => 'addressed_to_user'],
        ]);
});

test('an acknowledgement is not sent to the critic', function () {
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $message = criticMessage($user, $author, '👍', CarbonImmutable::parse('2026-03-13 15:00:00', 'UTC'));
    $segment = criticSegment($user, [$message], 'Un pulgar');
    $fake = criticScript([]);

    $reviews = app(CriticStage::class)->run($segment, [criticOp('acknowledge', '👍')], null);

    expect($reviews)->toHaveCount(1)
        ->and($reviews[0]->refuted)->toBeFalse()
        ->and($fake->calls)->toBe([]);
});

test('a refutation can quote the dossier', function () {
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $message = criticMessage($user, $author, '¿Sigue pendiente?', CarbonImmutable::parse('2026-03-13 15:00:00', 'UTC'));
    $segment = criticSegment($user, [$message], 'Pregunta si sigue');
    CommsTopicSearch::query()->create([
        'user_id' => $user->id,
        'topic_subject' => 'workstream:prepago',
        'document' => 'ya se envió el viernes',
    ]);
    $fake = criticScript([criticAnswer('already_done', 'ya se envió el viernes')]);

    $reviews = app(CriticStage::class)->run($segment, [criticOp('settle', '¿Sigue pendiente?')], 'workstream:prepago');

    expect($reviews[0]->refuted)->toBeTrue()
        ->and($fake->calls[0]['input'])->toContain('ya se envió el viernes')
        ->and($fake->calls[0]['input'])->toContain('¿Sigue pendiente?');
});

test('cannot tell leaves the operation standing', function () {
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $message = criticMessage($user, $author, 'Te lo mando el jueves', CarbonImmutable::parse('2026-03-13 15:00:00', 'UTC'));
    $segment = criticSegment($user, [$message], 'Una promesa');
    criticScript([[
        'addressed_to_user' => ['verdict' => 'cannot_tell', 'quote' => null],
        'already_done' => ['verdict' => 'cannot_tell', 'quote' => null],
        'real_ask' => ['verdict' => 'cannot_tell', 'quote' => null],
    ]]);

    $reviews = app(CriticStage::class)->run($segment, [criticOp('reopen', 'Te lo mando el jueves')], null);

    expect($reviews[0]->refuted)->toBeFalse()
        ->and($reviews[0]->operation->op())->toBe('reopen')
        ->and(CommsEvent::query()->where('type', EventType::StateApplied)->count())->toBe(0);
});

/**
 * @param  list<array<string, mixed>>  $bodies
 */
function criticScript(array $bodies): FakeCompleter
{
    $fake = new FakeCompleter;
    $fake->script = array_map(fn (array $body): ModelCompletion => new ModelCompletion($body, 'scripted'), $bodies);
    app()->instance(CompletesTasks::class, $fake);

    return $fake;
}

/**
 * @return array<string, mixed>
 */
function criticAnswer(string $question, string $quote): array
{
    $answer = [
        'addressed_to_user' => ['verdict' => 'holds', 'quote' => null],
        'already_done' => ['verdict' => 'holds', 'quote' => null],
        'real_ask' => ['verdict' => 'holds', 'quote' => null],
    ];
    $answer[$question] = ['verdict' => 'refuted', 'quote' => $quote];

    return $answer;
}

function criticOp(string $op, string $quote): AgreedOperation
{
    return new AgreedOperation([
        'op' => $op,
        'quote' => $quote,
        'what' => 'Confirmar la fecha',
    ], AgreementStatus::Agreed);
}

/**
 * @param  list<CommsMessage>  $messages
 */
function criticSegment(User $user, array $messages, string $restated): CommsSegment
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
        'restated' => $restated,
        'unresolved_refs' => [],
    ]);
}

function criticMessage(User $user, CommsPerson $author, string $text, CarbonImmutable $at): CommsMessage
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
