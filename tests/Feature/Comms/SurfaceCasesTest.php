<?php

use App\Comms\Events\Source;
use App\Comms\Gate\ActionClass;
use App\Comms\Gate\ProposalStatus;
use App\Comms\Notify\Notifier;
use App\Comms\People\Relation;
use App\Comms\Surface\HomeBoard;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsPreference;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\Comms\CommsReconcileChange;
use App\Models\User;
use Carbon\CarbonImmutable;

afterEach(function () {
    config(['comms.linking.surface_v2' => false]);
    CarbonImmutable::setTestNow();
});

test('a schedule shows the condition and one approval sends the parallel steps', function () {
    config(['comms.linking.surface_v2' => true]);
    $chats = new FakeChats;
    app()->instance(Chats::class, $chats);
    $user = caseUser();
    $intent = caseIntent($user, 'Quedar con Marta', [
        ['id' => 'slot', 'what' => 'crear el evento', 'condition' => 'Si Marta confirma', 'after' => [], 'channel' => 'calendar'],
    ]);
    $first = caseProposal($user, $intent, 'Avisar a Marta');
    $second = caseProposal($user, $intent, 'Crear el evento');

    $this->actingAs($user)->getJson(route('comms.intents.show', $intent))
        ->assertOk()
        ->assertJsonPath('open.plan.0.steps.0.text', 'Si Marta confirma: crear el evento')
        ->assertJsonPath('open.confirmations.0.kind', 'approve')
        ->assertJsonPath('open.confirmations.0.proposal_ids', [$first->id, $second->id]);

    $this->actingAs($user)->postJson(route('comms.intents.confirmations', $intent), [
        'kind' => 'approve',
        'answer' => 'approve',
        'proposal_ids' => [$first->id, $second->id],
    ])->assertOk();

    expect($first->refresh()->status)->toBe(ProposalStatus::Executed)
        ->and($second->refresh()->status)->toBe(ProposalStatus::Executed)
        ->and($chats->sent)->toHaveCount(2);
});

test('a status question shows the drafted answer and the source it cites', function () {
    config(['comms.linking.surface_v2' => true]);
    $user = caseUser();
    $intent = caseIntent($user, 'Cómo va el prepago', []);
    $author = casePerson($user, 'Marta');
    $message = CommsMessage::factory()->create([
        'author_person_id' => $author->id,
        'source' => Source::Notion,
        'text' => 'El prepago ya está en la especificación.',
    ]);
    CommsConversationLink::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'message_id' => $message->id,
        'operation' => 'open',
    ]);
    caseProposal($user, $intent, 'Ya está en la especificación.');

    $this->actingAs($user)->getJson(route('comms.intents.show', $intent))
        ->assertOk()
        ->assertJsonPath('open.confirmations.0.previews.0', 'Ya está en la especificación.')
        ->assertJsonPath('open.evidence.conversation.0.text', 'El prepago ya está en la especificación.');
});

test('a decision shows the options and the context, and the pick is recorded', function () {
    config(['comms.linking.surface_v2' => true]);
    $user = caseUser();
    $intent = caseIntent($user, 'Elegir el enfoque', []);
    $question = CommsQuestion::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'text' => '¿A o B?',
        'context' => ['summary' => 'A cuesta menos. B aguanta más carga.'],
        'options' => [
            ['id' => 'a', 'label' => 'Opción A'],
            ['id' => 'b', 'label' => 'Opción B'],
        ],
    ]);

    $this->actingAs($user)->getJson(route('comms.intents.show', $intent))
        ->assertOk()
        ->assertJsonPath('open.questions.0.text', '¿A o B?')
        ->assertJsonPath('open.questions.0.context', 'A cuesta menos. B aguanta más carga.')
        ->assertJsonPath('open.questions.0.options.0.label', 'Opción A')
        ->assertJsonPath('collapsed.pill', 'Tu decisión');

    $this->actingAs($user)->postJson(route('comms.intents.questions.pick', [$intent, $question]), [
        'option' => 'a',
    ])->assertOk()
        ->assertJsonPath('card.open.questions', []);

    expect($question->refresh()->answer)->toBe(['option' => 'a'])
        ->and($question->answered_event_id)->not->toBeNull();
});

test('a close deadline waiting on someone else moves up, and the alert stays one', function () {
    config(['comms.linking.surface_v2' => true]);
    $user = caseUser();
    $self = casePerson($user, 'Yo', Relation::Self);
    $marta = casePerson($user, 'Marta');
    $later = caseIntent($user, 'Enviar el informe', [], '2026-03-20');
    $soon = caseIntent($user, 'Cerrar el contrato', [], '2026-03-12', 'evt-soon');
    caseRole($user, $self, $later, 'requester');
    caseRole($user, $marta, $later, 'responder');
    caseRole($user, $self, $soon, 'requester');
    caseRole($user, $marta, $soon, 'responder');
    $notifier = app(Notifier::class);
    $notifier->store($user, 'signal', 'now', 'intent', $soon->id, 'n:soon:1', []);
    $notifier->store($user, 'signal', 'now', 'intent', $soon->id, 'n:soon:2', []);

    $waiting = collect(HomeBoard::for($user)['lists']['waiting_on_others'])->pluck('id')->all();

    expect($waiting)->toBe([$soon->id, $later->id])
        ->and(CommsNotification::query()->whereBelongsTo($user)->count())->toBe(1);
});

test('the morning briefing names what needs the user and the night changes', function () {
    config(['comms.linking.surface_v2' => true]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-17 09:30', 'Europe/Madrid'));
    $user = caseUser();
    CommsPreference::query()->create([
        'user_id' => $user->id,
        'timezone' => 'Europe/Madrid',
        'locale' => 'es',
        'work_start' => '09:00',
        'work_end' => '18:00',
        'work_days' => [1, 2, 3, 4, 5],
    ]);
    $self = casePerson($user, 'Yo', Relation::Self);
    $intent = caseIntent($user, 'Revisar el contrato', []);
    caseRole($user, $self, $intent, 'responder');
    CommsReconcileChange::query()->create([
        'user_id' => $user->id,
        'run_date' => '2026-11-16',
        'op' => 'relink',
        'payload' => ['op' => 'relink'],
        'quote' => 'La factura, no el contrato.',
        'why' => 'La noche lo vio con el día entero.',
        'disposition' => 'applied',
    ]);

    $this->actingAs($user)->getJson(route('comms.briefing'))
        ->assertOk()
        ->assertJsonPath('needs_you.0.what', 'Revisar el contrato')
        ->assertJsonPath('changes.0.quote', 'La factura, no el contrato.');
});

function caseUser(): User
{
    return User::factory()->create();
}

/**
 * @param  list<array<string, mixed>>  $steps
 */
function caseIntent(User $user, string $what, array $steps, ?string $deadline = null, string $key = 'evt-case'): CommsIntent
{
    return CommsIntent::factory()->create([
        'user_id' => $user->id,
        'key' => $key,
        'what' => $what,
        'state' => 'open',
        'steps' => $steps,
        'deadline' => $deadline,
        'importance' => 40,
    ]);
}

function caseProposal(User $user, CommsIntent $intent, string $text): CommsProposal
{
    return CommsProposal::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'action_class' => ActionClass::SendSlack,
        'status' => ProposalStatus::Pending,
        'payload' => ['text' => $text, 'to' => 'C1', 'after' => []],
    ]);
}

function casePerson(User $user, string $name, Relation $relation = Relation::Collaborator): CommsPerson
{
    return CommsPerson::factory()->create([
        'user_id' => $user->id,
        'display_name' => $name,
        'relation' => $relation,
    ]);
}

function caseRole(User $user, CommsPerson $person, CommsIntent $intent, string $role): void
{
    CommsIntentParticipant::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'person_id' => $person->id,
        'role' => $role,
    ]);
}
