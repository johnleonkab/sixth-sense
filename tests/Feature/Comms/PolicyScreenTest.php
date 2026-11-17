<?php

use App\Comms\Events\EventType;
use App\Comms\Gate\ActionClass;
use App\Comms\Gate\Gate;
use App\Comms\Gate\ProposalStatus;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsPolicy;
use App\Models\User;

afterEach(function () {
    config(['comms.linking.surface_v2' => false]);
});

test('the policies tab lists each action and the hard denies that stay blocked', function () {
    $user = catalogUser();

    $this->actingAs($user)
        ->get(route('settings.context.index', ['tab' => 'policies']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'policies')
            ->where('policies.classes.0.action_class', 'send_slack')
            ->where('policies.classes.0.label', 'Enviar un mensaje de Slack')
            ->where('policies.classes.0.outcome', 'ask')
            ->where('policies.denies.0.reason', 'sensitive')
            ->where('policies.denies.0.locked', true)
            ->where('policies.denies.0.label', 'Un mensaje sensible'));
});

test('saving a policy appends the event and the gate uses it on the next proposal', function () {
    $chats = new FakeChats;
    app()->instance(Chats::class, $chats);
    $user = catalogUser();

    $this->actingAs($user)
        ->put(route('settings.context.policies'), [
            'action_class' => 'send_slack',
            'outcome' => 'allow',
        ])
        ->assertRedirect();

    $event = CommsEvent::query()->whereBelongsTo($user)->where('type', EventType::UserConfigured)->sole();
    $proposal = app(Gate::class)->submit(
        user: $user,
        class: ActionClass::SendSlack,
        target: ['from_user' => true],
        payload: ['text' => 'Va el jueves', 'to' => 'C1'],
        notify: false,
    );

    expect($event->normalized['kind'])->toBe('policy')
        ->and($event->normalized['action_class'])->toBe('send_slack')
        ->and($event->normalized['outcome'])->toBe('allow')
        ->and(CommsPolicy::query()->whereBelongsTo($user)->where('action_class', 'send_slack')->sole()->outcome->value)->toBe('allow')
        ->and($proposal->status)->toBe(ProposalStatus::Executed)
        ->and($chats->sent)->toHaveCount(1);
});

test('never blocks the next proposal of that class', function () {
    $chats = new FakeChats;
    app()->instance(Chats::class, $chats);
    $user = catalogUser();

    $this->actingAs($user)
        ->put(route('settings.context.policies'), [
            'action_class' => 'send_slack',
            'outcome' => 'deny',
        ])
        ->assertRedirect();

    $proposal = app(Gate::class)->submit(
        user: $user,
        class: ActionClass::SendSlack,
        target: ['from_user' => true],
        payload: ['text' => 'Va el jueves', 'to' => 'C1'],
        notify: false,
    );

    expect($proposal->status)->toBe(ProposalStatus::Rejected)
        ->and($proposal->deny_reason)->toBe('policy')
        ->and($chats->sent)->toBeEmpty();
});

test('always without asking on a card is the same policy the screen shows', function () {
    config(['comms.linking.surface_v2' => true]);
    $user = catalogUser();
    $intent = CommsIntent::factory()->create([
        'user_id' => $user->id,
        'key' => 'evt-catalog',
        'what' => 'Enviar el contrato',
        'state' => 'open',
    ]);

    $this->actingAs($user)->postJson(route('comms.intents.always', $intent), [
        'action_class' => 'send_slack',
        'context' => ['channel' => 'C1'],
    ])->assertOk();

    $this->get(route('settings.context.index', ['tab' => 'policies']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('policies.classes.0.exceptions.0.context', 'Canal C1')
            ->where('policies.classes.0.exceptions.0.outcome', 'allow'));
});

test('a guest cannot change a policy', function () {
    $this->put(route('settings.context.policies'), [
        'action_class' => 'send_slack',
        'outcome' => 'allow',
    ])->assertRedirect(route('login'));
});

function catalogUser(): User
{
    return User::factory()->create();
}
