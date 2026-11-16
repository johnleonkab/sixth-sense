<?php

use App\Actions\ActionStatus;
use App\Comms\Events\Source;
use App\Comms\Gate\ActionClass;
use App\Comms\Gate\Gate;
use App\Comms\Gate\ProposalStatus;
use App\Comms\Intents\IntentStateMachine;
use App\Comms\Surface\HomeBoard;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

afterEach(function () {
    config(['comms.linking.surface_v2' => false]);
});

test('a deadline change after approval and before send returns the proposal to needs you', function () {
    config(['comms.linking.surface_v2' => true]);
    Queue::fake();
    $chats = new FakeChats;
    app()->instance(Chats::class, $chats);
    $user = holdUser();
    $intent = holdIntent($user, '2026-03-14');
    $proposal = app(Gate::class)->submit(
        user: $user,
        class: ActionClass::SendSlack,
        target: ['from_user' => true, 'channel' => 'C1'],
        payload: ['text' => 'Te lo mando el 14', 'to' => 'C1'],
        intent: $intent,
    );

    app(Gate::class)->approve($proposal->refresh());

    holdUpdate($user, $intent, '2026-03-12');

    $this->actingAs($user)->getJson(route('comms.intents.show', $intent))
        ->assertOk()
        ->assertJsonPath('open.confirmations.0.change', 'La fecha límite pasa del 2026-03-14 al 2026-03-12.');

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Pending)
        ->and($proposal->payload['change'])->toBe('La fecha límite pasa del 2026-03-14 al 2026-03-12.')
        ->and($proposal->pendingAction->status)->toBe(ActionStatus::Undone)
        ->and($intent->refresh()->deadline->toDateString())->toBe('2026-03-12')
        ->and(collect(HomeBoard::for($user)['lists']['needs_you'])->pluck('id')->all())->toBe([$intent->id])
        ->and($chats->sent)->toBeEmpty()
        ->and(CommsProposal::query()->where('intent_id', $intent->id)->count())->toBe(1);
});

test('an executed send is not repeated when the deadline moves, and a question says so', function () {
    $chats = new FakeChats;
    app()->instance(Chats::class, $chats);
    $user = holdUser();
    $intent = holdIntent($user, '2026-03-14');
    $proposal = app(Gate::class)->submit(
        user: $user,
        class: ActionClass::SendSlack,
        target: ['from_user' => true, 'channel' => 'C1'],
        payload: ['text' => 'Te lo mando el 14', 'to' => 'C1'],
        intent: $intent,
    );

    app(Gate::class)->approve($proposal->refresh());

    holdUpdate($user, $intent, '2026-03-12');

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Executed)
        ->and(CommsProposal::query()->where('intent_id', $intent->id)->count())->toBe(1)
        ->and($chats->sent)->toHaveCount(1)
        ->and(CommsQuestion::query()->where('intent_id', $intent->id)->sole()->text)
        ->toBe('Esto ya se envió. La fecha límite pasa del 2026-03-14 al 2026-03-12.');
});

function holdUser(): User
{
    return User::factory()->create();
}

function holdIntent(User $user, string $deadline): CommsIntent
{
    return CommsIntent::factory()->create([
        'user_id' => $user->id,
        'key' => 'evt-hold',
        'what' => 'Enviar el contrato',
        'state' => 'open',
        'deadline' => $deadline,
    ]);
}

function holdUpdate(User $user, CommsIntent $intent, string $deadline): void
{
    $author = CommsPerson::factory()->create([
        'user_id' => $user->id,
        'display_name' => 'Marta',
    ]);
    $message = CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $author->id,
        'source' => Source::Slack,
        'text' => 'Mejor el 12 de marzo',
    ]);

    app(IntentStateMachine::class)->effect($user, $message, [
        'op' => 'update',
        'intent_key' => $intent->key,
        'deadline' => $deadline,
        'quote' => 'Mejor el 12 de marzo',
        'importance' => 30,
    ], null, false);
}
