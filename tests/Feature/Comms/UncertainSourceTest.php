<?php

use App\Comms\Events\Source;
use App\Comms\Gate\ActionClass;
use App\Comms\Gate\Gate;
use App\Comms\Gate\PolicyOutcome;
use App\Comms\Gate\ProposalStatus;
use App\Comms\People\Relation;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsPolicy;
use App\Models\Comms\CommsProposal;
use App\Models\User;

afterEach(function () {
    config(['comms.linking.surface_v2' => false]);
});

test('an allow class still asks when the draft sits on an uncertain link', function () {
    config(['comms.linking.surface_v2' => true]);
    $chats = new FakeChats;
    app()->instance(Chats::class, $chats);
    $user = sourceUser();
    $intent = sourceIntent($user, 'Revisar el contrato');
    $uncertain = sourceMessage($user, '¿Me revisas el contrato?');
    sourceLink($user, $intent, $uncertain, true);
    $certain = sourceMessage($user, 'El contrato está en el drive.');
    sourceLink($user, $intent, $certain, false);

    $asked = app(Gate::class)->submit(
        user: $user,
        class: ActionClass::SendSlack,
        target: ['ref' => 'self', 'channel' => 'C1'],
        payload: ['text' => 'Borrador viejo', 'to' => 'C1'],
        intent: $intent,
        sourceEventIds: [$uncertain->event_id],
    );
    $sent = app(Gate::class)->submit(
        user: $user,
        class: ActionClass::SendSlack,
        target: ['ref' => 'self', 'channel' => 'C1'],
        payload: ['text' => 'Esto sí se envía', 'to' => 'C1'],
        intent: $intent,
        sourceEventIds: [$certain->event_id],
    );

    $this->actingAs($user)->getJson(route('comms.intents.show', $intent))
        ->assertOk()
        ->assertJsonPath('open.confirmations.0.kind', 'approve')
        ->assertJsonPath('open.confirmations.0.proposal_ids', [$asked->id])
        ->assertJsonPath('open.confirmations.0.uncertain_source_message_ids', [$uncertain->id])
        ->assertJsonPath('open.confirmations.0.notice', 'Este borrador se apoya en un enlace dudoso.');

    expect($asked->refresh()->status)->toBe(ProposalStatus::Pending)
        ->and($asked->policy_outcome)->toBe(PolicyOutcome::Allow)
        ->and($asked->uncertain_source_message_ids)->toBe([$uncertain->id])
        ->and($sent->refresh()->status)->toBe(ProposalStatus::Executed)
        ->and($sent->uncertain_source_message_ids)->toBe([])
        ->and($chats->sent)->toHaveCount(1)
        ->and($chats->sent[0]->text)->toBe('Esto sí se envía');
});

test('moving the uncertain message regenerates the draft', function () {
    config(['comms.linking.surface_v2' => true]);
    $chats = new FakeChats;
    app()->instance(Chats::class, $chats);
    $user = sourceUser();
    $intent = sourceIntent($user, 'Revisar el contrato');
    $other = sourceIntent($user, 'El contrato de marzo', 'evt-marzo');
    $message = sourceMessage($user, '¿Me revisas el contrato de marzo?');
    $link = sourceLink($user, $intent, $message, true, ['evt-marzo']);
    $old = app(Gate::class)->submit(
        user: $user,
        class: ActionClass::SendSlack,
        target: ['ref' => 'self', 'channel' => 'C1'],
        payload: ['text' => 'Borrador viejo', 'to' => 'C1'],
        intent: $intent,
        sourceEventIds: [$message->event_id],
    );
    CommsEvent::query()->where('user_id', $user->id)->whereNull('processed_at')->update(['processed_at' => now()]);

    $this->actingAs($user)->postJson(route('comms.intents.confirmations', $intent), [
        'kind' => 'disambiguate',
        'answer' => 'alternative',
        'link_id' => $link->id,
    ])->assertOk();

    $fresh = CommsProposal::query()->where('intent_id', $other->id)->sole();

    expect($old->refresh()->status)->toBe(ProposalStatus::Rejected)
        ->and($link->refresh()->intent_id)->toBe($other->id)
        ->and($fresh->payload['text'])->toBe('¿Me revisas el contrato de marzo?')
        ->and($fresh->uncertain_source_message_ids)->toBe([])
        ->and($fresh->status)->toBe(ProposalStatus::Executed)
        ->and($chats->sent)->toHaveCount(1)
        ->and($chats->sent[0]->text)->toBe('¿Me revisas el contrato de marzo?');
});

function sourceUser(): User
{
    $user = User::factory()->create();
    CommsPerson::factory()->create([
        'user_id' => $user->id,
        'relation' => Relation::Self,
        'display_name' => 'Yo',
    ]);
    CommsPolicy::factory()->create([
        'user_id' => $user->id,
        'action_class' => ActionClass::SendSlack,
        'conditions' => [],
        'outcome' => PolicyOutcome::Allow,
    ]);

    return $user;
}

function sourceIntent(User $user, string $what, string $key = 'evt-source'): CommsIntent
{
    return CommsIntent::factory()->create([
        'user_id' => $user->id,
        'key' => $key,
        'what' => $what,
        'state' => 'open',
    ]);
}

function sourceMessage(User $user, string $text): CommsMessage
{
    $author = CommsPerson::factory()->create([
        'user_id' => $user->id,
        'display_name' => 'Marta',
    ]);

    return CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $author->id,
        'source' => Source::Slack,
        'text' => $text,
    ]);
}

function sourceLink(User $user, CommsIntent $intent, CommsMessage $message, bool $uncertain, array $alternative = []): CommsConversationLink
{
    return CommsConversationLink::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'message_id' => $message->id,
        'uncertain' => $uncertain,
        'alternative' => $alternative,
    ]);
}
