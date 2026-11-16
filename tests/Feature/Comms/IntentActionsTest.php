<?php

use App\Actions\ActionStatus;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Gate\ActionClass;
use App\Comms\Gate\Gate;
use App\Comms\Gate\PolicyOutcome;
use App\Comms\Gate\ProposalStatus;
use App\Comms\People\Relation;
use App\Comms\Surface\HomeBoard;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsGoldenLabel;
use App\Models\Comms\CommsIdentity;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsWatcher;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

afterEach(function () {
    config(['comms.linking.surface_v2' => false]);
});

test('guidance rewrites the draft and does not send it', function () {
    config(['comms.linking.surface_v2' => true]);
    $chats = new FakeChats;
    app()->instance(Chats::class, $chats);
    $user = actUser();
    $intent = actIntent($user, 'Enviar el contrato');
    $proposal = actProposal($user, $intent, 'Te lo mando el viernes');

    $this->actingAs($user)->postJson(route('comms.intents.confirmations', $intent), [
        'kind' => 'approve',
        'answer' => 'guide',
        'proposal_ids' => [$proposal->id],
        'text' => 'Dile que el jueves me viene bien',
    ])->assertOk();

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Edited)
        ->and($proposal->payload['text'])->toBe('Dile que el jueves me viene bien')
        ->and(CommsEvent::query()->where('type', EventType::UserGuided)->count())->toBe(1)
        ->and($chats->sent)->toBeEmpty();
});

test('not for me still settles the intent and stores the example the critic reads', function () {
    config(['comms.linking.surface_v2' => true]);
    $user = actUser();
    $marta = actPerson($user, 'Marta');
    $intent = actIntent($user, 'Revisar el contrato', 'evt-drop');
    actRole($user, $intent, $marta, 'requester');
    actMark($user);

    $this->actingAs($user)->postJson(route('comms.intents.not-for-me', $intent))->assertOk();

    $label = CommsGoldenLabel::query()->where('user_id', $user->id)->where('kind', 'correction')->sole();

    expect($intent->refresh()->state)->toBe('settled')
        ->and($intent->settle_reason)->toBe('drop')
        ->and($label->label)->toBe('not_for_me')
        ->and($label->expected['text'])->toBe('Con Marta, «Revisar el contrato» no era para ella.');
});

test('delegating asks the other person to take it and the card waits on them', function () {
    config(['comms.linking.surface_v2' => true]);
    $user = actUser();
    $self = actPerson($user, 'Yo', Relation::Self);
    $marta = actPerson($user, 'Marta');
    $intent = actIntent($user, 'Enviar el contrato', 'evt-delegate');
    actRole($user, $intent, $self, 'requester');
    actRole($user, $intent, $self, 'responder');

    $this->actingAs($user)->postJson(route('comms.intents.delegate', $intent), [
        'person_id' => $marta->id,
    ])->assertOk()
        ->assertJsonPath('card.collapsed.who', 'Esperas a Marta');

    $proposal = CommsProposal::query()->where('intent_id', $intent->id)->sole();

    expect(collect(HomeBoard::for($user)['lists']['waiting_on_others'])->pluck('id')->all())->toBe([$intent->id])
        ->and($proposal->status)->toBe(ProposalStatus::Pending)
        ->and($proposal->payload['text'])->toBe('¿Puedes encargarte de «Enviar el contrato»?')
        ->and($proposal->payload['delegate'])->toBeTrue()
        ->and(CommsIntentParticipant::query()->where('intent_id', $intent->id)->where('role', 'responder')->sole()->person_id)->toBe($marta->id);
});

test('snooze hides the card until then and the deadline still counts', function () {
    config(['comms.linking.surface_v2' => true]);
    $user = actUser();
    $self = actPerson($user, 'Yo', Relation::Self);
    $intent = actIntent($user, 'Enviar el contrato', 'evt-snooze');
    $intent->forceFill(['deadline' => '2026-03-14'])->save();
    actRole($user, $intent, $self, 'responder');
    $watcher = CommsWatcher::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'state' => 'active',
    ]);

    $this->actingAs($user)->postJson(route('comms.intents.snooze', $intent), [
        'until' => now()->addDay()->toIso8601String(),
    ])->assertOk();

    expect(collect(HomeBoard::for($user)['lists']['needs_you'])->pluck('id')->all())->toBe([])
        ->and($intent->refresh()->deadline->toDateString())->toBe('2026-03-14')
        ->and($intent->snoozed_until)->not->toBeNull()
        ->and($watcher->refresh()->state)->toBe('active')
        ->and(CommsEvent::query()->where('type', EventType::UserSnoozed)->count())->toBe(1);
});

test('always do this allows the next send in that context, and a hard deny still blocks a client', function () {
    config(['comms.linking.surface_v2' => true]);
    $chats = new FakeChats;
    app()->instance(Chats::class, $chats);
    $user = actUser();
    $intent = actIntent($user, 'Enviar el contrato', 'evt-always');
    $client = actPerson($user, 'Cliente', Relation::Client);
    CommsIdentity::query()->create([
        'user_id' => $user->id,
        'person_id' => $client->id,
        'source' => Source::Slack,
        'external_id' => 'U-client',
        'email' => 'client@example.com',
    ]);

    $this->actingAs($user)->postJson(route('comms.intents.always', $intent), [
        'action_class' => ActionClass::SendSlack->value,
        'context' => ['channel' => 'C1'],
    ])->assertOk();

    $allowed = app(Gate::class)->submit(
        user: $user,
        class: ActionClass::SendSlack,
        target: ['from_user' => true, 'channel' => 'C1'],
        payload: ['text' => 'Va el jueves', 'to' => 'C1'],
        intent: $intent,
    );
    $denied = app(Gate::class)->submit(
        user: $user,
        class: ActionClass::SendSlack,
        target: ['ref' => 'slack:U-client', 'channel' => 'C1'],
        payload: ['text' => 'Hola', 'to' => 'C1', 'to_label' => 'Cliente'],
        intent: $intent,
    );

    expect(CommsEvent::query()->where('type', EventType::UserConfigured)->where('normalized->kind', 'policy')->count())->toBe(1)
        ->and($allowed->status)->toBe(ProposalStatus::Executed)
        ->and($allowed->policy_outcome)->toBe(PolicyOutcome::Allow)
        ->and($denied->deny_reason)->toBe('client')
        ->and($denied->status)->toBe(ProposalStatus::Rejected)
        ->and($chats->sent)->toHaveCount(1);
});

test('undo uses the window the send recorded and does not send', function () {
    Queue::fake();
    $chats = new FakeChats;
    app()->instance(Chats::class, $chats);
    $user = actUser();
    $intent = actIntent($user, 'Enviar el contrato', 'evt-undo');
    $proposal = app(Gate::class)->submit(
        user: $user,
        class: ActionClass::SendSlack,
        target: ['from_user' => true, 'channel' => 'C1'],
        payload: ['text' => 'Te lo mando el viernes', 'to' => 'C1'],
        intent: $intent,
    );
    app(Gate::class)->approve($proposal->refresh());

    $this->actingAs($user)->postJson(route('comms.proposals.undo', $proposal))->assertOk();

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Undone)
        ->and($proposal->pendingAction->status)->toBe(ActionStatus::Undone)
        ->and($chats->sent)->toBeEmpty();
});

function actUser(): User
{
    return User::factory()->create();
}

function actPerson(User $user, string $name, Relation $relation = Relation::Collaborator): CommsPerson
{
    return CommsPerson::factory()->create([
        'user_id' => $user->id,
        'display_name' => $name,
        'relation' => $relation,
    ]);
}

function actIntent(User $user, string $what, string $key = 'evt-act'): CommsIntent
{
    return CommsIntent::factory()->create([
        'user_id' => $user->id,
        'key' => $key,
        'what' => $what,
        'state' => 'open',
    ]);
}

function actRole(User $user, CommsIntent $intent, CommsPerson $person, string $role): void
{
    CommsIntentParticipant::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'person_id' => $person->id,
        'role' => $role,
    ]);
}

function actProposal(User $user, CommsIntent $intent, string $text): CommsProposal
{
    return CommsProposal::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'action_class' => ActionClass::SendSlack,
        'status' => ProposalStatus::Pending,
        'payload' => ['text' => $text, 'to' => 'C1'],
    ]);
}

function actMark(User $user): void
{
    CommsEvent::query()->where('user_id', $user->id)->whereNull('processed_at')->update(['processed_at' => now()]);
}
