<?php

use App\Comms\Events\EventType;
use App\Comms\Gate\ActionClass;
use App\Comms\Gate\ProposalStatus;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsProposal;
use App\Models\User;

afterEach(function () {
    config(['comms.linking.surface_v2' => false]);
});

test('sí confirms the open proposal', function () {
    config(['comms.linking.surface_v2' => true]);
    $chats = new FakeChats;
    app()->instance(Chats::class, $chats);
    $user = hearUser();
    $intent = hearIntent($user);
    $proposal = hearProposal($user, $intent, 'Te lo mando el viernes');

    $this->actingAs($user)->postJson(route('comms.intents.voice', $intent), [
        'transcript' => 'Sí.',
    ])->assertOk()
        ->assertJsonPath('heard', 'yes')
        ->assertJsonPath('card.open.confirmations', []);

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Executed)
        ->and($chats->sent)->toHaveCount(1)
        ->and($chats->sent[0]->text)->toBe('Te lo mando el viernes');
});

test('any other sentence rewrites the draft and shows it without sending', function () {
    config(['comms.linking.surface_v2' => true]);
    $chats = new FakeChats;
    app()->instance(Chats::class, $chats);
    $user = hearUser();
    $intent = hearIntent($user, 'evt-guide');
    $proposal = hearProposal($user, $intent, 'Te lo mando el viernes');

    $this->actingAs($user)->postJson(route('comms.intents.voice', $intent), [
        'transcript' => 'Dile que el jueves me viene bien',
    ])->assertOk()
        ->assertJsonPath('heard', 'guidance')
        ->assertJsonPath('card.open.confirmations.0.previews.0', 'Dile que el jueves me viene bien');

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Edited)
        ->and($proposal->payload['text'])->toBe('Dile que el jueves me viene bien')
        ->and(CommsEvent::query()->where('type', EventType::UserGuided)->count())->toBe(1)
        ->and($chats->sent)->toBeEmpty();
});

test('a yes with more words is guidance and does not send', function () {
    config(['comms.linking.surface_v2' => true]);
    $chats = new FakeChats;
    app()->instance(Chats::class, $chats);
    $user = hearUser();
    $intent = hearIntent($user, 'evt-mixed');
    $proposal = hearProposal($user, $intent, 'Te lo mando el viernes');

    $this->actingAs($user)->postJson(route('comms.intents.voice', $intent), [
        'transcript' => 'Sí, pero el jueves',
    ])->assertOk()
        ->assertJsonPath('heard', 'guidance');

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Edited)
        ->and($chats->sent)->toBeEmpty();
});

test('a sentence with no open proposal is left alone', function () {
    config(['comms.linking.surface_v2' => true]);
    $user = hearUser();
    $intent = hearIntent($user, 'evt-quiet');

    $this->actingAs($user)->postJson(route('comms.intents.voice', $intent), [
        'transcript' => 'Dile que el jueves',
    ])->assertOk()
        ->assertJsonPath('heard', 'none');

    expect(CommsEvent::query()->where('type', EventType::UserGuided)->count())->toBe(0);
});

test('voice on a proposal stays hidden while the surface flag is off', function () {
    $user = hearUser();
    $intent = hearIntent($user, 'evt-off');
    $proposal = hearProposal($user, $intent, 'Te lo mando el viernes');

    $this->actingAs($user)->postJson(route('comms.intents.voice', $intent), [
        'transcript' => 'Sí',
    ])->assertNotFound();

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Pending);
});

function hearUser(): User
{
    return User::factory()->create();
}

function hearIntent(User $user, string $key = 'evt-hear'): CommsIntent
{
    return CommsIntent::factory()->create([
        'user_id' => $user->id,
        'key' => $key,
        'what' => 'Enviar el contrato',
        'state' => 'open',
    ]);
}

function hearProposal(User $user, CommsIntent $intent, string $text): CommsProposal
{
    return CommsProposal::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'action_class' => ActionClass::SendSlack,
        'status' => ProposalStatus::Pending,
        'payload' => ['text' => $text, 'to' => 'C1', 'after' => []],
    ]);
}
