<?php

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Ingestion\IdentityResolver;
use App\Comms\Intents\CandidateRetriever;
use App\Comms\Intents\ImportanceCalculator;
use App\Comms\Messages\Direction;
use App\Comms\Messages\Message;
use App\Comms\Messages\Party;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Comms\People\Relation;
use App\Connections\Provider;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('slack teammates are collaborators so waiting asks score above the unknown floor', function () {
    $user = importanceUser();
    $ask = '¿Puedes revisar el flujo de prepago?';

    importanceScript([
        importanceHeard(),
        importanceMatch([importanceOpen('Revisar el flujo de prepago', $ask)]),
    ]);
    importanceSay($user, 's-1', $ask, new Party('U-patricia', 'patricia@cm.com', 'Patricia'), CarbonImmutable::parse('2026-11-11T10:00:00Z'));

    $intent = CommsIntent::query()->firstOrFail();
    $patricia = CommsPerson::query()->where('display_name', 'Patricia')->firstOrFail();

    expect($patricia->relation)->toBe(Relation::Collaborator)
        ->and($patricia->importance_weight)->toBe(25)
        ->and($intent->importance)->toBe(35);
});

test('same company email domain without slack still becomes a collaborator', function () {
    $user = importanceUser();
    $person = app(IdentityResolver::class)->resolve(
        $user,
        Source::Gmail,
        new Party('patricia@cm.com', 'patricia@cm.com', 'Patricia'),
    );

    expect($person->relation)->toBe(Relation::Collaborator)
        ->and($person->importance_weight)->toBe(25);
});

test('public mail domains stay unknown', function () {
    $user = importanceUser();
    $person = app(IdentityResolver::class)->resolve(
        $user,
        Source::Gmail,
        new Party('friend@gmail.com', 'friend@gmail.com', 'Friend'),
    );

    expect($person->relation)->toBe(Relation::Unknown)
        ->and($person->importance_weight)->toBe(5);
});

test('self is not stored twice as responder and possible responder', function () {
    $user = importanceUser();
    $ask = '¿Alguien puede mirar el contrato?';

    importanceScript([
        importanceHeard(),
        importanceMatch([importanceOpen('Revisar el contrato', $ask, responders: ['self'], extra: [
            'possible_responders' => ['self'],
        ])]),
    ]);
    importanceSay($user, 's-1', $ask, new Party('U-patricia', 'patricia@cm.com', 'Patricia'), CarbonImmutable::parse('2026-11-11T10:00:00Z'));

    $intent = CommsIntent::query()->firstOrFail();
    $roles = $intent->participants()->whereHas('person', fn ($query) => $query->where('relation', Relation::Self))
        ->pluck('role')
        ->sort()
        ->values()
        ->all();

    expect($roles)->toBe(['responder'])
        ->and($intent->participants()->where('role', 'possible_responder')->count())->toBe(0);
});

test('a near duplicate ask in the same channel attaches instead of opening again', function () {
    $user = importanceUser();
    $first = '¿Puedes revisar el flujo de prepago?';
    $second = '¿puedes revisar el flujo de prepago otra vez?';

    importanceScript([
        importanceHeard(),
        importanceMatch([importanceOpen('Revisar el flujo de prepago', $first)]),
    ]);
    importanceSay($user, 's-1', $first, new Party('U-patricia', 'patricia@cm.com', 'Patricia'), CarbonImmutable::parse('2026-11-11T10:00:00Z'), channel: 'D9', thread: '100.1');

    importanceScript([
        importanceHeard(),
        importanceMatch([importanceOpen('Revisar flujo prepago', $second)]),
    ]);
    importanceSay($user, 's-2', $second, new Party('U-patricia', 'patricia@cm.com', 'Patricia'), CarbonImmutable::parse('2026-11-11T10:02:00Z'), channel: 'D9', thread: '100.2');

    expect(CommsIntent::query()->count())->toBe(1)
        ->and(CommsConversationLink::query()->count())->toBe(2);
});

test('candidate retrieval ranks open intents from the same channel', function () {
    $user = importanceUser();
    $author = CommsPerson::factory()->create([
        'user_id' => $user->id,
        'display_name' => 'Patricia',
        'relation' => Relation::Collaborator,
        'importance_weight' => 25,
    ]);
    $intent = CommsIntent::factory()->for($user)->create(['state' => 'open', 'what' => 'Revisar Macarfi']);
    $root = CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $author->id,
        'source' => Source::Slack,
        'channel_ref' => 'D9',
        'thread_ref' => '100.1',
        'external_id' => 'T1:D9:100.1',
        'text' => 'Macarfi',
    ]);
    CommsConversationLink::query()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'message_id' => $root->id,
        'operation' => 'open',
        'confidence' => 0.9,
        'uncertain' => false,
    ]);
    CommsIntentParticipant::query()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'person_id' => $author->id,
        'role' => 'requester',
    ]);

    $next = CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $author->id,
        'source' => Source::Slack,
        'channel_ref' => 'D9',
        'thread_ref' => '100.2',
        'external_id' => 'T1:D9:100.2',
        'text' => 'y el formulario',
    ]);

    $candidates = app(CandidateRetriever::class)->for($user, $next);

    expect($candidates->pluck('id')->all())->toContain($intent->id);
});

test('importance calculator uses collaborator weight for waiting asks', function () {
    $user = importanceUser();
    $self = CommsPerson::query()->whereBelongsTo($user)->where('relation', Relation::Self)->firstOrFail();
    $patricia = CommsPerson::factory()->create([
        'user_id' => $user->id,
        'display_name' => 'Patricia',
        'relation' => Relation::Collaborator,
        'importance_weight' => 25,
    ]);
    $intent = CommsIntent::factory()->for($user)->create(['state' => 'open']);
    CommsIntentParticipant::query()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'person_id' => $patricia->id,
        'role' => 'requester',
    ]);
    CommsIntentParticipant::query()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'person_id' => $self->id,
        'role' => 'responder',
    ]);

    expect(app(ImportanceCalculator::class)->score($intent->fresh(['participants.person', 'links.message'])))->toBe(35);
});

function importanceUser(): User
{
    $user = User::factory()->create();

    ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Google,
        'email' => 'me@cm.com',
        'name' => 'Nicolás',
    ]);
    ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Slack,
        'email' => 'me@cm.com',
        'name' => 'Nicolás',
        'metadata' => ['user_id' => 'U-me', 'team_id' => 'T1'],
    ]);

    app(IdentityResolver::class)->ensureSelf($user);

    return $user;
}

/**
 * @param  list<array<string, mixed>>  $bodies
 */
function importanceScript(array $bodies): FakeCompleter
{
    $fake = new FakeCompleter;
    $fake->script = array_map(
        fn (array $body): ModelCompletion => new ModelCompletion($body, 'scripted'),
        $bodies,
    );
    app()->instance(CompletesTasks::class, $fake);

    return $fake;
}

/**
 * @return array<string, mixed>
 */
function importanceHeard(bool $urgent = false, bool $sensitive = false): array
{
    return [
        'label' => 'possible_intent',
        'urgent' => $urgent,
        'sensitive' => $sensitive,
        'confidence' => 0.9,
    ];
}

/**
 * @param  list<array<string, mixed>>  $operations
 * @return array<string, mixed>
 */
function importanceMatch(array $operations, float $confidence = 0.9): array
{
    return [
        'operations' => $operations,
        'confidence' => $confidence,
    ];
}

/**
 * @param  list<string>  $requesters
 * @param  list<string>  $responders
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function importanceOpen(string $what, string $quote, array $requesters = [], array $responders = ['self'], array $extra = []): array
{
    return [
        'op' => 'open',
        'what' => $what,
        'quote' => $quote,
        'confidence' => 0.9,
        'requesters' => $requesters === [] ? ['slack:U-patricia'] : $requesters,
        'responders' => $responders,
        ...$extra,
    ];
}

function importanceSay(
    User $user,
    string $id,
    string $text,
    Party $author,
    CarbonImmutable $at,
    string $channel = 'C1',
    string $thread = 'T1',
): void {
    $message = new Message(
        externalId: $id,
        source: Source::Slack,
        channel: $channel,
        thread: $thread,
        author: $author,
        recipients: [],
        direction: Direction::Incoming,
        at: $at,
        text: $text,
    );

    app(EventLog::class)->append(
        user: $user,
        type: EventType::MessageReceived,
        source: Source::Slack,
        externalId: $id,
        payload: null,
        normalized: $message->toArray(),
        causation: null,
        occurredAt: $at,
    );
}
