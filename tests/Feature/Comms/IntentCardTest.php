<?php

use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Gate\ProposalStatus;
use App\Comms\Intents\Pipeline;
use App\Comms\People\Relation;
use App\Comms\Surface\IntentCard;
use App\Models\Comms\CommsCardView;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsProposal;
use App\Models\User;
use Carbon\CarbonImmutable;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('opening a card records the view and keeps changed since until that look', function () {
    CarbonImmutable::setTestNow('2026-01-15 12:00:00');
    $user = User::factory()->create();
    $self = CommsPerson::factory()->create([
        'user_id' => $user->id,
        'relation' => Relation::Self,
        'display_name' => 'Yo',
    ]);
    $ana = CommsPerson::factory()->create([
        'user_id' => $user->id,
        'relation' => Relation::Collaborator,
        'display_name' => 'Ana',
    ]);
    $intent = CommsIntent::factory()->create([
        'user_id' => $user->id,
        'what' => 'Confirmar la fecha con Marta',
        'topic_subject' => 'workstream:prepago',
        'state' => 'open',
    ]);
    $intent->forceFill([
        'changed_summary' => 'Marta movió la fecha.',
        'changed_at' => CarbonImmutable::now()->subMinutes(4),
    ])->save();
    CommsIntentParticipant::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'person_id' => $ana->id,
        'role' => 'requester',
    ]);
    CommsIntentParticipant::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'person_id' => $self->id,
        'role' => 'responder',
    ]);
    CommsCardView::query()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'seen_at' => CarbonImmutable::now()->subHour(),
    ]);

    $before = app(IntentCard::class)->show($user, $intent);

    expect($before['collapsed']['what'])->toBe('Confirmar la fecha con Marta')
        ->and($before['collapsed']['topic'])->toBe('workstream:prepago')
        ->and($before['collapsed']['who'])->toBe('Ana espera tu respuesta')
        ->and($before['collapsed']['pill'])->toBe('Actualizado hace 4 min')
        ->and($before['open']['changed_since'])->toBe('Marta movió la fecha.');

    $opened = app(IntentCard::class)->open($user, $intent);
    $again = app(IntentCard::class)->show($user, $intent->fresh());
    $event = CommsEvent::query()->where('type', EventType::UserViewed)->sole();

    expect($opened['open']['changed_since'])->toBe('Marta movió la fecha.')
        ->and($again['open']['changed_since'])->toBeNull()
        ->and($again['collapsed']['pill'])->toBe('Te toca')
        ->and(CommsCardView::query()->whereBelongsTo($user)->sole()->seen_at->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and($event->source)->toBe(Source::User)
        ->and($event->normalized['intent_id'])->toBe($intent->id);

    CommsCardView::query()->delete();
    app(Pipeline::class)->advance($event);

    expect(CommsCardView::query()->whereBelongsTo($user)->sole()->intent_id)->toBe($intent->id)
        ->and(app(IntentCard::class)->open($other = User::factory()->create(), $intent))->toBeNull()
        ->and(CommsCardView::query()->whereBelongsTo($other)->count())->toBe(0);
});

test('an unclear field is asked in place of the value and evidence keeps every channel', function () {
    CarbonImmutable::setTestNow('2026-01-15 12:00:00');
    $user = User::factory()->create();
    $self = CommsPerson::factory()->create([
        'user_id' => $user->id,
        'relation' => Relation::Self,
        'display_name' => 'Yo',
    ]);
    $ana = CommsPerson::factory()->create([
        'user_id' => $user->id,
        'display_name' => 'Ana',
    ]);
    $intent = CommsIntent::factory()->create([
        'user_id' => $user->id,
        'what' => 'Revisar el contrato',
        'unclear_fields' => ['responders', 'deadline'],
        'deadline' => '2026-01-20',
        'steps' => [['id' => 's1', 'what' => 'Leer el contrato', 'after' => []]],
    ]);
    CommsIntentParticipant::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'person_id' => $self->id,
        'role' => 'requester',
    ]);
    CommsIntentParticipant::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'person_id' => $ana->id,
        'role' => 'responder',
    ]);
    $slack = CommsMessage::factory()->create([
        'author_person_id' => $ana->id,
        'source' => Source::Slack,
        'channel_ref' => 'C-ask',
        'text' => '¿Puedes revisar el contrato?',
        'occurred_at' => CarbonImmutable::now()->subHours(2),
    ]);
    $mail = CommsMessage::factory()->create([
        'author_person_id' => $ana->id,
        'source' => Source::Gmail,
        'channel_ref' => 'inbox',
        'text' => 'Te lo reenvío por correo.',
        'occurred_at' => CarbonImmutable::now()->subHour(),
    ]);
    CommsConversationLink::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'message_id' => $slack->id,
        'operation' => 'open',
    ]);
    CommsConversationLink::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'message_id' => $mail->id,
        'operation' => 'attach',
    ]);
    CommsProposal::factory()->count(2)->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'status' => ProposalStatus::Pending,
    ]);
    CommsProposal::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'status' => ProposalStatus::Rejected,
    ]);

    $card = app(IntentCard::class)->show($user, $intent);

    expect($card['collapsed']['who'])->toBe('¿Quién debería hacerlo?')
        ->and($card['collapsed']['deadline'])->toBe('¿Para cuándo?')
        ->and($card['collapsed']['channels'])->toBe(['slack', 'gmail'])
        ->and($card['collapsed']['pill'])->toBe('Aprobar 2')
        ->and($card['open']['responders'])->toBe([])
        ->and($card['open']['requesters'])->toBe(['Yo'])
        ->and($card['open']['unclear'])->toBe([
            'responders' => '¿Quién debería hacerlo?',
            'deadline' => '¿Para cuándo?',
        ])
        ->and($card['open']['plan'])->toBe([['id' => 's1', 'what' => 'Leer el contrato', 'after' => []]])
        ->and($card['open']['actions'])->toHaveCount(2)
        ->and($card['open']['evidence']['quote'])->toBe('¿Puedes revisar el contrato?')
        ->and($card['open']['evidence']['message_count'])->toBe(2)
        ->and($card['open']['evidence']['channel_count'])->toBe(2)
        ->and(collect($card['open']['evidence']['conversation'])->pluck('text')->all())->toBe([
            '¿Puedes revisar el contrato?',
            'Te lo reenvío por correo.',
        ]);
});
