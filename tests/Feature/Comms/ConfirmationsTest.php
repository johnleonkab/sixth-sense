<?php

use App\Comms\Events\Source;
use App\Comms\Gate\ActionClass;
use App\Comms\Gate\ProposalStatus;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsProposal;
use App\Models\User;

afterEach(function () {
    config(['comms.linking.surface_v2' => false]);
});

test('parallel steps are one approve card, and one tap sends them together', function () {
    config(['comms.linking.surface_v2' => true]);
    $chats = new FakeChats;
    app()->instance(Chats::class, $chats);
    $user = threeUser();
    $intent = threeIntent($user, 'Cerrar el lanzamiento');
    $first = threeProposal($user, $intent, 'Avisar a Marta', []);
    $second = threeProposal($user, $intent, 'Crear el evento', []);
    $later = threeProposal($user, $intent, 'Enviar el resumen', ['wait']);

    $this->actingAs($user)->getJson(route('comms.intents.show', $intent))
        ->assertOk()
        ->assertJsonPath('open.confirmations.0.kind', 'approve')
        ->assertJsonPath('open.confirmations.0.proposal_ids', [$first->id, $second->id])
        ->assertJsonPath('open.confirmations.0.answers', [
            ['id' => 'approve', 'label' => 'Aprobar'],
            ['id' => 'edit', 'label' => 'Editar'],
            ['id' => 'guide', 'label' => 'Guiar'],
            ['id' => 'reject', 'label' => 'Rechazar'],
        ])
        ->assertJsonPath('open.confirmations.1.proposal_ids', [$later->id]);

    $this->actingAs($user)->postJson(route('comms.intents.confirmations', $intent), [
        'kind' => 'approve',
        'answer' => 'approve',
        'proposal_ids' => [$first->id, $second->id],
    ])->assertOk()
        ->assertJsonPath('card.open.confirmations.0.proposal_ids', [$later->id]);

    expect($first->refresh()->status)->toBe(ProposalStatus::Executed)
        ->and($second->refresh()->status)->toBe(ProposalStatus::Executed)
        ->and($later->refresh()->status)->toBe(ProposalStatus::Pending)
        ->and($chats->sent)->toHaveCount(2);
});

test('the confirmation stays hidden while the surface flag is off', function () {
    $user = threeUser();
    $intent = threeIntent($user, 'Cerrar el lanzamiento');
    $proposal = threeProposal($user, $intent, 'Avisar a Marta', []);

    $this->actingAs($user)->postJson(route('comms.intents.confirmations', $intent), [
        'kind' => 'approve',
        'answer' => 'approve',
        'proposal_ids' => [$proposal->id],
    ])->assertNotFound();

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Pending);
});

test('edit and reject change the draft without sending, and guide waits', function () {
    config(['comms.linking.surface_v2' => true]);
    $chats = new FakeChats;
    app()->instance(Chats::class, $chats);
    $user = threeUser();
    $edited = threeIntent($user, 'Editar el aviso');
    $edit = threeProposal($user, $edited, 'Hola', []);
    $rejected = threeIntent($user, 'Rechazar el aviso', 'evt-reject');
    $reject = threeProposal($user, $rejected, 'No enviar', []);
    $guided = threeIntent($user, 'Guiar el aviso', 'evt-guide');
    $guide = threeProposal($user, $guided, 'Borrador', []);

    $this->actingAs($user)->postJson(route('comms.intents.confirmations', $edited), [
        'kind' => 'approve',
        'answer' => 'edit',
        'proposal_ids' => [$edit->id],
        'text' => 'Hola Marta, el jueves',
    ])->assertOk();

    $this->actingAs($user)->postJson(route('comms.intents.confirmations', $rejected), [
        'kind' => 'approve',
        'answer' => 'reject',
        'proposal_ids' => [$reject->id],
    ])->assertOk();

    $this->actingAs($user)->postJson(route('comms.intents.confirmations', $guided), [
        'kind' => 'approve',
        'answer' => 'guide',
        'proposal_ids' => [$guide->id],
    ])->assertOk();

    expect($edit->refresh()->status)->toBe(ProposalStatus::Edited)
        ->and($edit->payload['text'])->toBe('Hola Marta, el jueves')
        ->and($reject->refresh()->status)->toBe(ProposalStatus::Rejected)
        ->and($guide->refresh()->status)->toBe(ProposalStatus::Pending)
        ->and($chats->sent)->toBeEmpty();
});

test('an uncertain link offers this, the alternative, or somewhere else', function () {
    config(['comms.linking.surface_v2' => true]);
    $user = threeUser();
    $intent = threeIntent($user, 'Revisar el contrato');
    $other = threeIntent($user, 'El contrato de marzo', 'evt-marzo');
    $message = threeMessage($user, '¿Es el contrato de marzo?');
    $link = threeLink($user, $intent, $message, true, ['evt-marzo']);
    $lost = threeMessage($user, 'Esto es otra cosa');
    $lostLink = threeLink($user, $intent, $lost, true, ['evt-marzo']);
    threeMarkProcessed($user);

    $this->actingAs($user)->getJson(route('comms.intents.show', $intent))
        ->assertOk()
        ->assertJsonPath('open.confirmations.0.kind', 'disambiguate')
        ->assertJsonPath('open.confirmations.0.link_id', $link->id)
        ->assertJsonPath('open.confirmations.0.prompt', '¿Es esto?')
        ->assertJsonPath('open.confirmations.0.answers.1', ['id' => 'alternative', 'label' => 'El contrato de marzo'])
        ->assertJsonPath('open.confirmations.0.answers.2', ['id' => 'somewhere_else', 'label' => 'En otro sitio']);

    $this->actingAs($user)->postJson(route('comms.intents.confirmations', $intent), [
        'kind' => 'disambiguate',
        'answer' => 'confirm',
        'link_id' => $link->id,
    ])->assertOk();

    $this->actingAs($user)->postJson(route('comms.intents.confirmations', $intent), [
        'kind' => 'disambiguate',
        'answer' => 'alternative',
        'link_id' => $lostLink->id,
    ])->assertOk();

    expect($link->refresh()->uncertain)->toBeFalse()
        ->and($link->intent_id)->toBe($intent->id)
        ->and($lostLink->refresh()->intent_id)->toBe($other->id)
        ->and($lostLink->uncertain)->toBeFalse();
});

test('somewhere else drops the link and does not attach the alternative', function () {
    config(['comms.linking.surface_v2' => true]);
    $user = threeUser();
    $intent = threeIntent($user, 'Revisar el contrato');
    $other = threeIntent($user, 'El contrato de marzo', 'evt-marzo');
    $message = threeMessage($user, 'No es ninguno de los dos');
    $link = threeLink($user, $intent, $message, true, ['evt-marzo']);
    threeMarkProcessed($user);

    $this->actingAs($user)->postJson(route('comms.intents.confirmations', $intent), [
        'kind' => 'disambiguate',
        'answer' => 'somewhere_else',
        'link_id' => $link->id,
    ])->assertOk();

    expect(CommsConversationLink::query()->find($link->id))->toBeNull()
        ->and(CommsConversationLink::query()->where('intent_id', $other->id)->count())->toBe(0);
});

test('an unclear close asks done or still open, and a clear close stays closed until undo', function () {
    config(['comms.linking.surface_v2' => true]);
    $user = threeUser();
    $unclear = threeIntent($user, 'El ok de Marta');
    $unclear->forceFill(['acknowledged_at' => now()])->save();
    $kept = threeIntent($user, 'Sigue en marcha', 'evt-kept');
    $kept->forceFill(['acknowledged_at' => now()])->save();
    $clear = threeIntent($user, 'Ya cerrado', 'evt-clear');
    $clear->forceFill([
        'state' => 'settled',
        'settle_reason' => 'decision',
        'settled_at' => now(),
    ])->save();
    $field = threeIntent($user, 'Falta la fecha', 'evt-field');
    $field->forceFill(['unclear_fields' => ['deadline']])->save();

    $this->actingAs($user)->getJson(route('comms.intents.show', $unclear))
        ->assertOk()
        ->assertJsonPath('open.confirmations.0.kind', 'outcome')
        ->assertJsonPath('open.confirmations.0.answers', [
            ['id' => 'done', 'label' => 'Hecho'],
            ['id' => 'still_open', 'label' => 'Sigue abierto'],
        ]);

    $this->actingAs($user)->getJson(route('comms.intents.show', $clear))
        ->assertOk()
        ->assertJsonPath('open.confirmations', []);

    $this->actingAs($user)->getJson(route('comms.intents.show', $field))
        ->assertOk()
        ->assertJsonPath('open.confirmations.0.kind', 'disambiguate')
        ->assertJsonPath('open.confirmations.0.field', 'deadline')
        ->assertJsonPath('open.confirmations.0.prompt', '¿Para cuándo?');

    $this->actingAs($user)->postJson(route('comms.intents.confirmations', $unclear), [
        'kind' => 'outcome',
        'answer' => 'done',
    ])->assertOk();

    $this->actingAs($user)->postJson(route('comms.intents.confirmations', $kept), [
        'kind' => 'outcome',
        'answer' => 'still_open',
    ])->assertOk();

    $this->actingAs($user)->postJson(route('comms.intents.confirmations', $clear), [
        'kind' => 'outcome',
        'answer' => 'still_open',
    ])->assertOk();

    $this->actingAs($user)->postJson(route('comms.intents.confirmations', $field), [
        'kind' => 'disambiguate',
        'answer' => 'confirm',
        'field' => 'deadline',
    ])->assertOk();

    expect($unclear->refresh()->state)->toBe('settled')
        ->and($unclear->settle_reason)->toBe('decision')
        ->and($kept->refresh()->state)->toBe('open')
        ->and($kept->acknowledged_at)->toBeNull()
        ->and($clear->refresh()->state)->toBe('open')
        ->and($clear->settle_reason)->toBeNull()
        ->and($field->refresh()->unclear_fields)->toBeNull();
});

function threeUser(): User
{
    return User::factory()->create();
}

function threeIntent(User $user, string $what, string $key = 'evt-three'): CommsIntent
{
    return CommsIntent::factory()->create([
        'user_id' => $user->id,
        'key' => $key,
        'what' => $what,
        'state' => 'open',
    ]);
}

function threeProposal(User $user, CommsIntent $intent, string $text, array $after): CommsProposal
{
    return CommsProposal::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'action_class' => ActionClass::SendSlack,
        'status' => ProposalStatus::Pending,
        'payload' => ['text' => $text, 'to' => 'C1', 'after' => $after],
    ]);
}

function threeMessage(User $user, string $text): CommsMessage
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

function threeLink(User $user, CommsIntent $intent, CommsMessage $message, bool $uncertain, array $alternative): CommsConversationLink
{
    return CommsConversationLink::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'message_id' => $message->id,
        'uncertain' => $uncertain,
        'alternative' => $alternative,
    ]);
}

function threeMarkProcessed(User $user): void
{
    CommsEvent::query()->where('user_id', $user->id)->whereNull('processed_at')->update(['processed_at' => now()]);
}
