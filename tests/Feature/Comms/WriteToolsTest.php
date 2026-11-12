<?php

use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Gate\ActionClass;
use App\Comms\Gate\Gate;
use App\Comms\Gate\ProposalStatus;
use App\Comms\Ingestion\IdentityResolver;
use App\Comms\People\Relation;
use App\Comms\Playbooks\Steps\Propose;
use App\Connections\Provider;
use App\Connectors\Calendar\CalendarWriter;
use App\Connectors\Calendar\FakeCalendarWriter;
use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\FakeOutbox;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\MailSender;
use App\Connectors\Mail\Outbox;
use App\Connectors\Wiki\FakeWiki;
use App\Connectors\Wiki\Wiki;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('approving a calendar block creates a real event and undo cancels it', function () {
    $writer = new FakeCalendarWriter;
    app()->instance(CalendarWriter::class, $writer);
    $user = writeToolsUser();
    $intent = CommsIntent::factory()->for($user)->create();
    $proposal = app(Gate::class)->submit(
        user: $user,
        class: ActionClass::CalendarCreate,
        target: ['ref' => 'self'],
        payload: [
            'text' => 'Bloque: loyalty',
            'title' => 'Bloque: loyalty',
            'starts_at' => '2026-11-09T09:00:00Z',
            'ends_at' => '2026-11-09T09:30:00Z',
        ],
        intent: $intent,
    );

    app(Gate::class)->approve($proposal->refresh());

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Executed)
        ->and($proposal->external_id)->toStartWith('demo:')
        ->and($writer->changes)->toHaveCount(1)
        ->and($writer->changes[0][0])->toBe('create')
        ->and(CommsEvent::query()->where('type', EventType::ActionExecuted)->count())->toBe(1)
        ->and(CommsEvent::query()->where('type', EventType::MessageSent)->count())->toBe(0);

    $undone = app(Gate::class)->undo($proposal->refresh());

    expect($undone->status)->toBe(ProposalStatus::Undone)
        ->and($undone->payload['deleted'])->toBeTrue()
        ->and($writer->changes[1][0])->toBe('cancel');
});

test('approving a notion page creates it under a parent and does not echo a message', function () {
    $wiki = new FakeWiki;
    app()->instance(Wiki::class, $wiki);
    $user = writeToolsUser();
    $intent = CommsIntent::factory()->for($user)->create(['what' => 'Agendar reunión técnica']);
    $proposal = app(Gate::class)->submit(
        user: $user,
        class: ActionClass::NotionCreatePage,
        target: ['ref' => 'self'],
        payload: [
            'title' => 'Agendar reunión técnica',
            'text' => 'Hay que sacarlo de la hoja.',
            'ref' => 'notion:'.$intent->key,
        ],
        intent: $intent,
    );

    app(Gate::class)->approve($proposal->refresh());

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Executed)
        ->and($proposal->external_id)->toStartWith('demo:new-')
        ->and($wiki->created)->toHaveCount(1)
        ->and($wiki->created[0]['parent'])->toBe(FakeWiki::LAUNCH)
        ->and($wiki->created[0]['title'])->toBe('Agendar reunión técnica')
        ->and(CommsEvent::query()->where('type', EventType::MessageSent)->count())->toBe(0);
});

test('a gmail reply proposal uses the sendable account and keeps thread headers', function () {
    $user = writeToolsUser();
    $account = $user->connectedAccounts()->where('provider', Provider::Google)->firstOrFail();
    $outbox = new class($account->id, (string) $account->email) extends FakeOutbox
    {
        public function __construct(private int $id, private string $address) {}

        public function senders(User $user): array
        {
            return [new MailSender($this->id, $this->address)];
        }

        public function senderFor(User $user, string $originalRef): ?MailSender
        {
            return $this->senders($user)[0];
        }
    };
    app()->instance(Outbox::class, $outbox);
    app()->instance(Mailbox::class, new FakeMailbox);
    $person = CommsPerson::query()->create([
        'user_id' => $user->id,
        'display_name' => 'Gabriel',
        'relation' => Relation::Collaborator,
        'importance_weight' => Relation::Collaborator->weight(),
    ]);
    $person->identities()->create([
        'user_id' => $user->id,
        'source' => Source::Gmail,
        'external_id' => 'gabriel@theimigroup.eu',
        'email' => 'gabriel@theimigroup.eu',
    ]);
    $intent = CommsIntent::factory()->for($user)->create(['what' => 'Responder a Gabriel']);
    CommsIntentParticipant::query()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'person_id' => $person->id,
        'role' => 'requester',
    ]);
    $message = CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $person->id,
        'source' => Source::Gmail,
        'channel_ref' => 'inbox',
        'thread_ref' => 'thread-1',
        'external_id' => 'msg-1',
        'text' => 'Google Pay works.',
    ]);

    $proposal = app(Propose::class)->submit($user, $intent, $message, 'Perfecto, gracias.', [], facts: false);

    expect($proposal->action_class)->toBe(ActionClass::SendEmailReply)
        ->and($proposal->payload['account_id'])->toBe($account->id)
        ->and($proposal->payload['from'])->toBe('me@example.com')
        ->and($proposal->payload['to_email'])->toBe('gabriel@theimigroup.eu')
        ->and($proposal->payload['original'])->toBe($account->id.'.msg-1');

    app(Gate::class)->approve($proposal->refresh());

    expect($outbox->sent)->toHaveCount(1)
        ->and($outbox->sent[0]->accountId)->toBe($account->id)
        ->and($outbox->sent[0]->from)->toBe('me@example.com')
        ->and($outbox->sent[0]->to[0]->email)->toBe('gabriel@theimigroup.eu')
        ->and($outbox->sent[0]->threadId)->toBe('thread-1')
        ->and($outbox->sent[0]->mode)->toBe('reply')
        ->and(CommsEvent::query()->where('type', EventType::MessageSent)->count())->toBe(1);
});

function writeToolsUser(): User
{
    $user = User::factory()->create(['name' => 'Nicolás']);

    ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Google,
        'email' => 'me@example.com',
        'name' => 'Nicolás',
        'scopes' => Provider::Google->scopes(),
    ]);
    app(IdentityResolver::class)->ensureSelf($user);

    return $user;
}
