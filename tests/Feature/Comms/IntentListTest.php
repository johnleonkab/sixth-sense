<?php

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\People\Relation;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIdentity;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsMetric;
use App\Models\Comms\CommsOwnedArea;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsPreference;
use App\Models\User;
use Carbon\CarbonImmutable;

test('the home groups an open intent by who it is waiting on', function () {
    [$user, $self, $marta] = boardPeople();
    $mine = boardIntent($user, $self, $marta, 'Revisar el flujo de prepago', 'responder', 'requester', now(), 60);
    boardIntent($user, $self, $marta, 'Enviar el contrato', 'requester', 'responder', now(), 15, 'evt-others', 'T1:C1:2.2');
    boardIntent($user, $self, $marta, 'La nota vieja', 'responder', 'requester', now()->subDays(10), 5, 'evt-quiet', 'T1:C1:3.2');

    $this->actingAs($user)->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->where('intents.waiting_on_me.0.what', 'Revisar el flujo de prepago')
            ->where('intents.waiting_on_me.0.who', 'Marta')
            ->where('intents.waiting_on_me.0.importance', 'alta')
            ->where('intents.waiting_on_me.0.source_url', 'https://slack.com/archives/C1/p12')
            ->where('intents.waiting_on_others.0.what', 'Enviar el contrato')
            ->where('intents.quiet.0.what', 'La nota vieja')
            ->missing('intents.waiting_on_me.1'));

    expect($mine->key)->toBe('evt-mine');
});

test('closing and reopening an intent is a correction', function () {
    [$user, $self, $marta] = boardPeople();
    $intent = boardIntent($user, $self, $marta, 'Revisar el flujo de prepago', 'responder', 'requester');

    $this->actingAs($user)
        ->from(route('home'))
        ->post(route('intents.corrections.store', ['key' => $intent->key]), ['action' => 'close'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($intent->refresh()->state)->toBe('settled')
        ->and($intent->settle_reason)->toBe('drop');

    $this->actingAs($user)
        ->post(route('intents.corrections.store', ['key' => $intent->key]), ['action' => 'still_open'])
        ->assertRedirect();

    expect($intent->refresh()->state)->toBe('open')
        ->and($intent->settle_reason)->toBeNull();
});

test('an importance correction lasts thirty days', function () {
    [$user, $self, $marta] = boardPeople();
    $intent = boardIntent($user, $self, $marta, 'Revisar el flujo de prepago', 'responder', 'requester');

    $this->actingAs($user)->post(route('intents.corrections.store', ['key' => $intent->key]), [
        'action' => 'importance',
        'importance' => 80,
    ])->assertRedirect();

    expect($intent->refresh()->importance)->toBe(80)
        ->and($intent->importance_override_until->greaterThan(now()->addDays(29)))->toBeTrue();
});

test('an uncertain message can be moved or split, and two intents can merge', function () {
    [$user, $self, $marta] = boardPeople();
    $first = boardIntent($user, $self, $marta, 'Revisar el flujo de prepago', 'responder', 'requester', key: 'evt-a', externalId: 's-a', uncertain: true);
    $second = boardIntent($user, $self, $marta, 'Enviar el contrato', 'responder', 'requester', key: 'evt-b', externalId: 's-b');
    $message = CommsMessage::query()->where('external_id', 's-a')->firstOrFail();

    $this->actingAs($user)->post(route('intents.corrections.store', ['key' => $first->key]), [
        'action' => 'rematch',
        'target_key' => $second->key,
        'message_source' => 'slack',
        'message_external_id' => 's-a',
    ])->assertRedirect();

    expect(CommsConversationLink::query()->where('intent_id', $second->id)->where('message_id', $message->id)->where('uncertain', false)->exists())->toBeTrue()
        ->and(CommsConversationLink::query()->where('intent_id', $first->id)->where('message_id', $message->id)->exists())->toBeFalse();

    $third = boardIntent($user, $self, $marta, 'Preparar la demo', 'responder', 'requester', key: 'evt-c', externalId: 's-c');
    $extra = CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $marta->id,
        'external_id' => 's-c-2',
        'text' => 'Y también el guion',
        'channel_ref' => 'C1',
    ]);
    CommsConversationLink::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $third->id,
        'message_id' => $extra->id,
        'uncertain' => true,
    ]);
    CommsEvent::query()->where('user_id', $user->id)->whereNull('processed_at')->update(['processed_at' => now()]);

    $this->actingAs($user)->post(route('intents.corrections.store', ['key' => $third->key]), [
        'action' => 'split',
        'what' => 'Y también el guion',
        'messages' => [['source' => 'slack', 'external_id' => 's-c-2']],
    ])->assertRedirect();

    $split = CommsIntent::query()->where('what', 'Y también el guion')->firstOrFail();

    expect($split->state)->toBe('open')
        ->and($split->links()->where('message_id', $extra->id)->exists())->toBeTrue()
        ->and($third->links()->where('message_id', $extra->id)->exists())->toBeFalse();

    $this->actingAs($user)->post(route('intents.corrections.store', ['key' => $first->key]), [
        'action' => 'merge',
        'target_key' => $second->key,
    ])->assertRedirect();

    expect($first->refresh()->state)->toBe('settled')
        ->and($first->settle_reason)->toBe('merged')
        ->and($second->links()->whereHas('message', fn ($query) => $query->where('external_id', 's-a'))->exists())->toBeTrue();
});

test('people settings are stored as configuration events', function () {
    [$user, $self, $marta] = boardPeople();

    $this->actingAs($user)->get(route('settings.people.index'))
        ->assertInertia(fn ($page) => $page
            ->component('settings/people')
            ->where('people.0.name', 'Marta')
            ->where('preference.timezone', 'Europe/Madrid'));

    $this->actingAs($user)->put(route('settings.people.update', $marta), [
        'relation' => 'client',
        'importance_weight' => 35,
    ])->assertRedirect();

    expect($marta->refresh()->relation)->toBe(Relation::Client)
        ->and($marta->importance_weight)->toBe(35)
        ->and(CommsEvent::query()->where('type', EventType::UserConfigured)->exists())->toBeTrue();

    $this->actingAs($user)->put(route('settings.people.preference'), [
        'timezone' => 'Atlantic/Canary',
        'locale' => 'es',
        'work_start' => '08:30',
        'work_end' => '17:30',
        'work_days' => [1, 2, 3, 4],
    ])->assertRedirect();

    expect(CommsPreference::query()->firstOrFail()->timezone)->toBe('Atlantic/Canary')
        ->and(CommsPreference::query()->firstOrFail()->work_days)->toBe([1, 2, 3, 4]);

    $this->actingAs($user)->post(route('settings.people.areas.store'), [
        'kind' => 'product',
        'ref' => 'prepago',
        'name' => 'Prepago',
        'keywords' => 'prepago, cobro',
    ])->assertRedirect();

    $area = CommsOwnedArea::query()->firstOrFail();

    expect($area->keywords)->toBe(['prepago', 'cobro']);

    $this->actingAs($user)->delete(route('settings.people.areas.destroy', $area))->assertRedirect();

    expect(CommsOwnedArea::query()->count())->toBe(0)
        ->and($self->relation)->toBe(Relation::Self);
});

test('the daily count separates rule drops, model drops and later misses', function () {
    [$user, $self, $marta] = boardPeople();
    $day = CarbonImmutable::parse('2026-11-08T12:00:00Z');
    $ruled = CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $marta->id,
        'direction' => 'in',
        'occurred_at' => $day,
        'flags' => ['noise' => true, 'label' => 'noise', 'cc_only' => false, 'urgent' => false, 'sensitive' => false, 'automated' => false],
    ]);
    $modeled = CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $marta->id,
        'direction' => 'in',
        'external_id' => 'modeled',
        'occurred_at' => $day,
        'flags' => ['noise' => true, 'label' => 'noise', 'cc_only' => false, 'urgent' => false, 'sensitive' => false, 'automated' => false],
    ]);

    app(EventLog::class)->append($user, EventType::ModelDecided, Source::Model, null, null, [
        'task' => 'triage',
        'output' => ['label' => 'noise'],
        'event_ids' => [$modeled->event_id],
    ], null, $day);
    $applied = app(EventLog::class)->append($user, EventType::StateApplied, Source::Slack, 'opened-1', null, [
        'results' => [['op' => 'open', 'status' => 'accepted'], ['op' => 'settle', 'status' => 'rejected']],
    ], null, $day);
    app(EventLog::class)->append($user, EventType::UserCorrected, Source::User, 'miss-1', null, [
        'action' => 'rematch',
        'intent_key' => 'missing',
        'message_source' => 'slack',
        'message_external_id' => $ruled->external_id,
    ], null, $day);

    $intent = boardIntent($user, $self, $marta, 'Algo', 'responder', 'requester', $day, key: 'evt-metric', externalId: 'metric-msg');
    CommsConversationLink::query()->where('intent_id', $intent->id)->update([
        'uncertain' => true,
        'state_event_id' => $applied->id,
    ]);

    $this->artisan('comms:metrics', ['user' => $user->id, '--date' => '2026-11-08'])->assertSuccessful();

    $row = CommsMetric::query()->whereBelongsTo($user)->firstOrFail();

    expect($row->messages_in)->toBe(3)
        ->and($row->dropped_by_rule)->toBe(1)
        ->and($row->dropped_by_model)->toBe(1)
        ->and($row->intents_opened)->toBe(1)
        ->and($row->uncertain_links)->toBe(1)
        ->and($row->misses)->toBe(1)
        ->and($row->corrections)->toBe(['rematch' => 1]);
});

/**
 * @return array{0: User, 1: CommsPerson, 2: CommsPerson}
 */
function boardPeople(): array
{
    $user = User::factory()->create();
    $self = CommsPerson::factory()->create([
        'user_id' => $user->id,
        'display_name' => 'Nicolás',
        'relation' => Relation::Self,
        'importance_weight' => 0,
    ]);
    $marta = CommsPerson::factory()->create([
        'user_id' => $user->id,
        'display_name' => 'Marta',
        'relation' => Relation::Unknown,
        'importance_weight' => 5,
    ]);
    CommsIdentity::factory()->create([
        'user_id' => $user->id,
        'person_id' => $marta->id,
        'source' => Source::Slack,
        'external_id' => 'U-marta',
        'email' => 'marta@example.com',
    ]);

    return [$user, $self, $marta];
}

function boardIntent(
    User $user,
    CommsPerson $self,
    CommsPerson $other,
    string $what,
    string $selfRole,
    string $otherRole,
    mixed $activity = null,
    int $importance = 30,
    string $key = 'evt-mine',
    string $externalId = 'T1:C1:1.2',
    bool $uncertain = false,
): CommsIntent {
    $intent = CommsIntent::factory()->create([
        'user_id' => $user->id,
        'key' => $key,
        'what' => $what,
        'state' => 'open',
        'importance' => $importance,
        'flags' => [],
        'last_activity_at' => $activity ?? now(),
        'last_change_summary' => 'Revisa esto',
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
        'links' => [],
        'occurred_at' => $activity ?? now(),
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
