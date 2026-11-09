<?php

use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Gate\ActionClass;
use App\Comms\Gate\Gate;
use App\Comms\Gate\PolicyOutcome;
use App\Comms\Gate\ProposalExpiry;
use App\Comms\Gate\ProposalStatus;
use App\Comms\Ingestion\IdentityResolver;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Comms\People\Relation;
use App\Connections\Provider;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Connectors\Mail\FakeOutbox;
use App\Connectors\Mail\Outbox;
use App\Jobs\ExecutePendingAction;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIdentity;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\ConnectedAccount;
use App\Models\PendingAction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('a proposal sends once and approving twice does not send again', function () {
    $user = phase4User();
    phase4Marta($user);
    $chats = phase4Chats();
    phase4Noise();

    $proposal = app(Gate::class)->submit(
        $user,
        ActionClass::SendSlack,
        ['ref' => 'slack:U-marta', 'channel' => 'C1', 'thread' => 'T1'],
        ['text' => '¿Lo tienes?', 'to' => 'C1', 'to_label' => 'Marta', 'thread_ref' => 'T1'],
    );

    $this->actingAs($user)->post(route('comms.proposals.approve', $proposal))->assertRedirect();
    $this->actingAs($user)->post(route('comms.proposals.approve', $proposal))->assertRedirect();

    $sent = CommsEvent::query()->where('type', EventType::MessageSent)->sole();

    expect($chats->sent)->toHaveCount(1)
        ->and($chats->sent[0]->text)->toBe('¿Lo tienes?')
        ->and($chats->sent[0]->threadRef)->toBe('T1')
        ->and(PendingAction::query()->count())->toBe(1)
        ->and($proposal->refresh()->status)->toBe(ProposalStatus::Executed)
        ->and($sent->causation->type)->toBe(EventType::ActionExecuted)
        ->and($sent->causation->normalized['proposal_id'])->toBe($proposal->id)
        ->and($sent->external_id)->toBe($proposal->external_id);
});

test('undo inside the window appends action.undone and sends nothing', function () {
    Queue::fake([ExecutePendingAction::class]);
    $user = phase4User();
    phase4Marta($user);
    $chats = phase4Chats();

    $proposal = app(Gate::class)->submit(
        $user,
        ActionClass::SendSlack,
        ['ref' => 'slack:U-marta', 'channel' => 'C1'],
        ['text' => '¿Lo tienes?', 'to' => 'C1', 'to_label' => 'Marta'],
    );

    $this->actingAs($user)->post(route('comms.proposals.approve', $proposal))->assertRedirect();
    $this->actingAs($user)->post(route('comms.proposals.undo', $proposal))->assertRedirect();

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Undone)
        ->and($proposal->pendingAction->status->value)->toBe('undone')
        ->and($chats->sent)->toBeEmpty()
        ->and(CommsEvent::query()->where('type', EventType::ActionUndone)->count())->toBe(1);

    Queue::assertPushed(ExecutePendingAction::class);
});

test('a sensitive proposal is denied with no pending action', function () {
    $user = phase4User();
    phase4Marta($user);
    $intent = CommsIntent::factory()->create([
        'user_id' => $user->id,
        'flags' => ['sensitive' => true],
    ]);

    $proposal = app(Gate::class)->submit(
        $user,
        ActionClass::SendSlack,
        ['ref' => 'slack:U-marta', 'channel' => 'C1'],
        ['text' => 'un dato sensible', 'to' => 'C1', 'to_label' => 'Marta'],
        intent: $intent,
    );

    expect($proposal->policy_outcome)->toBe(PolicyOutcome::Deny)
        ->and($proposal->deny_reason)->toBe('sensitive')
        ->and(PendingAction::query()->count())->toBe(0);
});

test('a client recipient is denied unless the user initiated the send', function () {
    $user = phase4User();
    $client = CommsPerson::query()->create([
        'user_id' => $user->id,
        'display_name' => 'Cliente',
        'relation' => Relation::Client,
        'importance_weight' => Relation::Client->weight(),
    ]);
    CommsIdentity::query()->create([
        'user_id' => $user->id,
        'person_id' => $client->id,
        'source' => Source::Slack,
        'external_id' => 'U-client',
        'email' => 'client@example.com',
    ]);

    $denied = app(Gate::class)->submit($user, ActionClass::SendSlack, ['ref' => 'slack:U-client', 'channel' => 'C1'], [
        'text' => 'hola',
        'to' => 'C1',
        'to_label' => 'Cliente',
    ]);
    $asked = app(Gate::class)->submit($user, ActionClass::SendSlack, ['ref' => 'slack:U-client', 'channel' => 'C1'], [
        'text' => 'hola',
        'to' => 'C1',
        'to_label' => 'Cliente',
    ], userInitiated: true);

    expect($denied->deny_reason)->toBe('client')
        ->and(PendingAction::query()->count())->toBe(0)
        ->and($asked->policy_outcome)->toBe(PolicyOutcome::Ask)
        ->and($asked->status)->toBe(ProposalStatus::Pending);
});

test('a recipient that appears only in a message body is denied', function () {
    $user = phase4User();

    $proposal = app(Gate::class)->submit($user, ActionClass::SendSlack, ['ref' => 'name:Alguien'], [
        'text' => 'dile a Alguien que mire esto',
        'to' => 'C1',
        'to_label' => 'Alguien',
    ]);

    expect($proposal->deny_reason)->toBe('target')
        ->and(PendingAction::query()->count())->toBe(0);
});

test('reject stores rejected and sends nothing', function () {
    $user = phase4User();
    phase4Marta($user);
    $chats = phase4Chats();
    $proposal = app(Gate::class)->submit(
        $user,
        ActionClass::SendSlack,
        ['ref' => 'slack:U-marta', 'channel' => 'C1'],
        ['text' => 'no envíes esto', 'to' => 'C1', 'to_label' => 'Marta'],
    );

    $this->actingAs($user)->post(route('comms.proposals.reject', $proposal))->assertRedirect();

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Rejected)
        ->and($chats->sent)->toBeEmpty()
        ->and(PendingAction::query()->count())->toBe(0);
});

test('an email reply keeps the thread headers', function () {
    $user = phase4User();
    $outbox = new FakeOutbox;
    app()->instance(Outbox::class, $outbox);
    phase4Noise();

    $proposal = app(Gate::class)->submit($user, ActionClass::SendEmailReply, [
        'ref' => 'self',
        'channel' => 'INBOX',
        'thread' => 'gmail-1',
    ], [
        'text' => 'aquí va',
        'to_email' => 'marta@example.com',
        'to_name' => 'Marta',
        'from' => 'me@example.com',
        'subject' => 'Re: el contrato',
        'thread_id' => 'gmail-1',
        'in_reply_to' => '<marta@example.com>',
        'references' => '<marta@example.com>',
    ], userInitiated: true);

    $this->actingAs($user)->post(route('comms.proposals.approve', $proposal))->assertRedirect();

    expect($outbox->sent)->toHaveCount(1)
        ->and($outbox->sent[0]->mode)->toBe('reply')
        ->and($outbox->sent[0]->inReplyTo)->toBe('<marta@example.com>')
        ->and($outbox->sent[0]->references)->toBe('<marta@example.com>')
        ->and($outbox->sent[0]->threadId)->toBe('gmail-1');
});

test('the follow-up draft is editable and becomes a proposal', function () {
    $user = phase4User();
    $marta = phase4Marta($user);
    $intent = CommsIntent::factory()->create(['user_id' => $user->id, 'flags' => []]);
    CommsIntentParticipant::query()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'person_id' => $marta->id,
        'role' => 'responder',
    ]);
    $message = CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $marta->id,
        'source' => Source::Slack,
        'channel_ref' => 'C1',
        'thread_ref' => 'T1',
        'text' => '¿lo tienes?',
    ]);
    CommsConversationLink::query()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'message_id' => $message->id,
        'operation' => 'open',
        'confidence' => 0.9,
        'uncertain' => false,
    ]);
    $question = CommsQuestion::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'context' => ['draft' => ''],
        'options' => [],
    ]);
    CommsEvent::query()->whereBelongsTo($user)->whereNull('processed_at')->update(['processed_at' => now()]);

    $this->actingAs($user)->post(route('shadow.questions.draft', $question), ['draft' => 'Te lo reclamo hoy.'])->assertRedirect();

    expect($question->refresh()->context['draft'])->toBe('Te lo reclamo hoy.');

    $this->actingAs($user)->post(route('shadow.questions.send', $question))->assertRedirect();

    $proposal = CommsProposal::query()->whereBelongsTo($user)->sole();

    expect($proposal->status)->toBe(ProposalStatus::Pending)
        ->and($proposal->payload['text'])->toBe('Te lo reclamo hoy.')
        ->and($proposal->user_initiated)->toBeTrue()
        ->and(PendingAction::query()->count())->toBe(0);

    Http::preventStrayRequests(false);

    $this->actingAs($user)->get(route('shadow.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('shadow')
            ->where('items.0.proposal.status', 'pending')
            ->where('items.0.proposal.text', 'Te lo reclamo hoy.'));
});

test('a proposal nobody decides expires after seventy two hours', function () {
    Carbon::setTestNow('2026-11-01 10:00:00');
    $user = phase4User();
    phase4Marta($user);
    $proposal = app(Gate::class)->submit(
        $user,
        ActionClass::SendSlack,
        ['ref' => 'slack:U-marta', 'channel' => 'C1'],
        ['text' => 'luego', 'to' => 'C1', 'to_label' => 'Marta'],
    );

    Carbon::setTestNow('2026-11-04 11:00:00');
    app(ProposalExpiry::class)->expire($user);

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Expired);
});

test('another person cannot approve a proposal', function () {
    $user = phase4User();
    phase4Marta($user);
    $proposal = app(Gate::class)->submit(
        $user,
        ActionClass::SendSlack,
        ['ref' => 'slack:U-marta', 'channel' => 'C1'],
        ['text' => 'hola', 'to' => 'C1', 'to_label' => 'Marta'],
    );

    $this->post(route('comms.proposals.approve', $proposal))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->post(route('comms.proposals.approve', $proposal))->assertNotFound();
});

function phase4User(): User
{
    $user = User::factory()->create();

    ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Google,
        'email' => 'me@example.com',
        'name' => 'Nicolás',
    ]);
    ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Slack,
        'email' => 'me@example.com',
        'name' => 'Nicolás',
        'metadata' => ['user_id' => 'U-me'],
    ]);
    app(IdentityResolver::class)->ensureSelf($user);

    return $user;
}

function phase4Marta(User $user): CommsPerson
{
    $marta = CommsPerson::query()->create([
        'user_id' => $user->id,
        'display_name' => 'Marta',
        'relation' => Relation::Collaborator,
        'importance_weight' => Relation::Collaborator->weight(),
    ]);
    CommsIdentity::query()->create([
        'user_id' => $user->id,
        'person_id' => $marta->id,
        'source' => Source::Slack,
        'external_id' => 'U-marta',
        'email' => 'marta@example.com',
    ]);

    return $marta;
}

function phase4Chats(): FakeChats
{
    $chats = new FakeChats;
    app()->instance(Chats::class, $chats);

    return $chats;
}

function phase4Noise(): void
{
    $fake = new FakeCompleter;
    $fake->script = [new ModelCompletion([
        'label' => 'noise',
        'urgent' => false,
        'sensitive' => false,
        'confidence' => 0.9,
    ], 'scripted')];
    app()->instance(CompletesTasks::class, $fake);
}
