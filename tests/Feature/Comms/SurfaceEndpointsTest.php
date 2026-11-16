<?php

use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\People\Relation;
use App\Events\HomeCountsChanged;
use App\Events\IntentCardChanged;
use App\Models\Comms\CommsCardView;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\User;
use Illuminate\Support\Facades\Event;

afterEach(function () {
    config(['comms.linking.surface_v2' => false]);
});

test('the home page keeps Para mí until the surface flag is on', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('surfaceV2', false)
            ->where('surface', null)
            ->has('intents'));

    config(['comms.linking.surface_v2' => true]);

    $this->actingAs($user)->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('surfaceV2', true)
            ->where('surface.counters.needs_you', 0)
            ->where('surface.possible', [])
            ->where('surface.uncertain', [])
            ->has('intents'));
});

test('a guest is sent to login', function () {
    $this->get(route('comms.home'))->assertRedirect(route('login'));
});

test('the surface stays hidden while its flag is off', function () {
    $user = User::factory()->create();
    $intent = CommsIntent::factory()->create(['user_id' => $user->id, 'what' => 'Revisar el contrato']);

    $this->actingAs($user)->getJson(route('comms.home'))->assertNotFound();
    $this->actingAs($user)->getJson(route('comms.intents.show', $intent))->assertNotFound();
    $this->actingAs($user)->postJson(route('comms.intents.done', $intent))->assertNotFound();

    expect($intent->refresh()->state)->toBe('open');
});

test('the home returns the four counters', function () {
    config(['comms.linking.surface_v2' => true]);
    [$user, $self, $marta] = surfacePeople();
    surfaceIntent($user, $self, $marta, 'Revisar el contrato', 'responder', 'requester');

    $this->actingAs($user)->getJson(route('comms.home'))
        ->assertOk()
        ->assertJsonPath('counters', [
            'needs_you' => 1,
            'system_working' => 0,
            'waiting_on_others' => 0,
            'done_today' => 0,
        ])
        ->assertJsonPath('lists.needs_you.0.what', 'Revisar el contrato')
        ->assertJsonPath('possible_asks', 0)
        ->assertJsonPath('uncertain_links', 0);
});

test('opening an intent records the view and another person gets 404', function () {
    config(['comms.linking.surface_v2' => true]);
    [$user, $self, $marta] = surfacePeople();
    $intent = surfaceIntent($user, $self, $marta, 'Revisar el contrato', 'responder', 'requester');
    $other = User::factory()->create();

    $this->actingAs($user)->getJson(route('comms.intents.show', $intent))
        ->assertOk()
        ->assertJsonPath('collapsed.what', 'Revisar el contrato')
        ->assertJsonPath('open.changed_since', null);

    expect(CommsCardView::query()->whereBelongsTo($user)->sole()->intent_id)->toBe($intent->id);

    $this->actingAs($other)->getJson(route('comms.intents.show', $intent))->assertNotFound();
    expect(CommsCardView::query()->whereBelongsTo($other)->count())->toBe(0);
});

test('a topic lists its intents and does not mark them seen', function () {
    config(['comms.linking.surface_v2' => true]);
    [$user, $self, $marta] = surfacePeople();
    surfaceIntent($user, $self, $marta, 'La importante', 'responder', 'requester', 'workstream:prepago', 40, 'evt-high');
    surfaceIntent($user, $self, $marta, 'La secundaria', 'responder', 'requester', 'workstream:prepago', 10, 'evt-low');
    surfaceIntent($user, $self, $marta, 'Otra cosa', 'responder', 'requester', 'workstream:otro', 90, 'evt-other');

    $this->actingAs($user)->getJson(route('comms.topics.show', ['subject' => 'workstream:prepago']))
        ->assertOk()
        ->assertJsonPath('subject', 'workstream:prepago')
        ->assertJsonPath('intents.0.collapsed.what', 'La importante')
        ->assertJsonPath('intents.1.collapsed.what', 'La secundaria')
        ->assertJsonMissing(['collapsed' => ['what' => 'Otra cosa']]);

    expect(CommsCardView::query()->count())->toBe(0);

    $this->actingAs($user)->getJson(route('comms.topics.show', ['subject' => 'workstream:nadie']))->assertNotFound();
});

test('confirming a link clears the doubt and tells the open home', function () {
    config(['comms.linking.surface_v2' => true]);
    Event::fake([HomeCountsChanged::class, IntentCardChanged::class]);
    [$user, $self, $marta] = surfacePeople();
    $intent = surfaceIntent($user, $self, $marta, 'Revisar el contrato', 'responder', 'requester', uncertain: true);
    $link = CommsConversationLink::query()->where('intent_id', $intent->id)->sole();
    $other = User::factory()->create();

    $this->actingAs($other)->postJson(route('comms.links.confirm', $link))->assertNotFound();
    expect($link->refresh()->uncertain)->toBeTrue();

    $this->actingAs($user)->postJson(route('comms.links.confirm', $link))
        ->assertOk()
        ->assertJsonPath('card.collapsed.what', 'Revisar el contrato');

    expect($link->refresh()->uncertain)->toBeFalse();

    Event::assertDispatched(HomeCountsChanged::class, function (HomeCountsChanged $event) use ($user): bool {
        return $event->broadcastAs() === 'home.counts.changed'
            && $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$user->id
            && $event->broadcastWith()['uncertain_links'] === 0;
    });
    Event::assertDispatched(IntentCardChanged::class, function (IntentCardChanged $event) use ($user, $intent): bool {
        return $event->broadcastAs() === 'intent.card.changed'
            && $event->intentId === $intent->id
            && $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$user->id
            && $event->broadcastWith()['intent_id'] === $intent->id;
    });
});

test('moving a link puts it on the other intent', function () {
    config(['comms.linking.surface_v2' => true]);
    Event::fake([HomeCountsChanged::class, IntentCardChanged::class]);
    [$user, $self, $marta] = surfacePeople();
    $from = surfaceIntent($user, $self, $marta, 'Revisar el contrato', 'responder', 'requester', uncertain: true, key: 'evt-from', externalId: 'msg-from');
    $to = surfaceIntent($user, $self, $marta, 'Enviar el contrato', 'responder', 'requester', key: 'evt-to', externalId: 'msg-to');
    $link = CommsConversationLink::query()->where('intent_id', $from->id)->sole();
    $messageId = $link->message_id;

    $this->actingAs($user)->postJson(route('comms.links.move', $link))->assertJsonValidationErrors('target_id');
    expect($link->refresh()->intent_id)->toBe($from->id);

    $this->actingAs($user)->postJson(route('comms.links.move', $link), ['target_id' => $from->id])->assertUnprocessable();
    expect($link->refresh()->intent_id)->toBe($from->id);

    $stranger = CommsIntent::factory()->create(['what' => 'Ajeno']);

    $this->actingAs($user)->postJson(route('comms.links.move', $link), ['target_id' => $stranger->id])->assertUnprocessable();
    expect($link->refresh()->intent_id)->toBe($from->id);

    $this->actingAs($user)->postJson(route('comms.links.move', $link), ['target_id' => $to->id])
        ->assertOk()
        ->assertJsonPath('from.collapsed.what', 'Revisar el contrato')
        ->assertJsonPath('to.collapsed.what', 'Enviar el contrato');

    expect(CommsConversationLink::query()->where('intent_id', $to->id)->where('message_id', $messageId)->where('uncertain', false)->exists())->toBeTrue()
        ->and(CommsConversationLink::query()->where('intent_id', $from->id)->where('message_id', $messageId)->exists())->toBeFalse();

    Event::assertDispatched(IntentCardChanged::class, fn (IntentCardChanged $event): bool => $event->intentId === $from->id);
    Event::assertDispatched(IntentCardChanged::class, fn (IntentCardChanged $event): bool => $event->intentId === $to->id);
});

test('not for me settles it and keeps the example, and done still open and importance do what they say', function () {
    config(['comms.linking.surface_v2' => true]);
    Event::fake([HomeCountsChanged::class, IntentCardChanged::class]);
    [$user, $self, $marta] = surfacePeople();
    $dropped = surfaceIntent($user, $self, $marta, 'Revisar el contrato', 'responder', 'requester', key: 'evt-drop');
    $finished = surfaceIntent($user, $self, $marta, 'Enviar el acta', 'responder', 'requester', key: 'evt-done', externalId: 'msg-done');
    $reopened = surfaceIntent($user, $self, $marta, 'Preparar la demo', 'responder', 'requester', key: 'evt-open', externalId: 'msg-open');
    $reopened->forceFill(['state' => 'settled', 'settle_reason' => 'decision', 'settled_at' => now()])->save();
    $ranked = surfaceIntent($user, $self, $marta, 'Cambiar la fecha', 'responder', 'requester', key: 'evt-rank', externalId: 'msg-rank', importance: 30);

    $this->actingAs($user)->postJson(route('comms.intents.not-for-me', $dropped))->assertOk();
    $this->actingAs($user)->postJson(route('comms.intents.done', $finished))->assertOk();
    $this->actingAs($user)->postJson(route('comms.intents.still-open', $reopened))->assertOk();
    $this->actingAs($user)->postJson(route('comms.intents.importance', $ranked), ['importance' => 101])->assertJsonValidationErrors('importance');

    expect($ranked->refresh()->importance)->toBe(30);

    $this->actingAs($user)->postJson(route('comms.intents.importance', $ranked), ['importance' => 80])->assertOk();

    expect($dropped->refresh()->state)->toBe('settled')
        ->and($dropped->settle_reason)->toBe('drop')
        ->and($finished->refresh()->settle_reason)->toBe('decision')
        ->and($reopened->refresh()->state)->toBe('open')
        ->and($reopened->settle_reason)->toBeNull()
        ->and($ranked->refresh()->importance)->toBe(80)
        ->and($ranked->importance_override_until->greaterThan(now()->addDays(29)))->toBeTrue();

    $example = CommsEvent::query()->where('type', EventType::ContextCorrected)->get()->first(
        fn (CommsEvent $event): bool => ($event->normalized['operations'][0]['value']['text'] ?? null) === 'Con Marta, «Revisar el contrato» no era para ella.',
    );

    expect($example)->not->toBeNull()
        ->and($example->normalized['operations'][0]['audiences'])->toBe(['match']);
});

/**
 * @return array{0: User, 1: CommsPerson, 2: CommsPerson}
 */
function surfacePeople(): array
{
    $user = User::factory()->create();
    $self = CommsPerson::factory()->create([
        'user_id' => $user->id,
        'display_name' => 'Yo',
        'relation' => Relation::Self,
    ]);
    $marta = CommsPerson::factory()->create([
        'user_id' => $user->id,
        'display_name' => 'Marta',
        'relation' => Relation::Collaborator,
    ]);

    return [$user, $self, $marta];
}

function surfaceIntent(
    User $user,
    CommsPerson $self,
    CommsPerson $other,
    string $what,
    string $selfRole,
    string $otherRole,
    ?string $topic = null,
    int $importance = 30,
    string $key = 'evt-surface',
    string $externalId = '',
    bool $uncertain = false,
): CommsIntent {
    $externalId = $externalId !== '' ? $externalId : $key;
    $intent = CommsIntent::factory()->create([
        'user_id' => $user->id,
        'key' => $key,
        'what' => $what,
        'state' => 'open',
        'importance' => $importance,
        'topic_subject' => $topic,
    ]);

    foreach ([[$self, $selfRole], [$other, $otherRole]] as [$person, $role]) {
        CommsIntentParticipant::factory()->create([
            'user_id' => $user->id,
            'intent_id' => $intent->id,
            'person_id' => $person->id,
            'role' => $role,
        ]);
    }

    $message = CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $other->id,
        'source' => Source::Slack,
        'external_id' => $externalId,
        'channel_ref' => 'C1',
        'text' => $what,
        'occurred_at' => now(),
    ]);
    CommsConversationLink::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'message_id' => $message->id,
        'uncertain' => $uncertain,
    ]);
    CommsEvent::query()->where('user_id', $user->id)->whereNull('processed_at')->update(['processed_at' => now()]);

    return $intent;
}
