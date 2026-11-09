<?php

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Replay;
use App\Comms\Events\Source;
use App\Comms\Ingestion\IdentityResolver;
use App\Comms\Messages\Direction;
use App\Comms\Messages\Message;
use App\Comms\Messages\Party;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Comms\People\Relation;
use App\Connections\Provider;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIdentity;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsQuestion;
use App\Models\Comms\CommsWatcher;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 11:00:00', 'UTC'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('case 5 asks once, then a different question, then goes quiet', function () {
    [, $intent, $watcher] = phase3Waiting();

    $this->artisan('comms:tick')->assertSuccessful();

    $first = CommsQuestion::query()->sole();

    expect($first->text)->toContain('¿Lo reclamo?')
        ->and($first->context['draft'])->toBe('')
        ->and($watcher->refresh()->attempt)->toBe(1)
        ->and(CommsNotification::query()->where('kind', 'question')->count())->toBe(1);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 11:00:00', 'UTC'));
    $this->artisan('comms:tick')->assertSuccessful();

    $texts = CommsQuestion::query()->orderBy('id')->pluck('text');

    expect($texts)->toHaveCount(2)
        ->and($texts[1])->toContain('otro canal')
        ->and($texts[0])->not->toBe($texts[1]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 11:00:00', 'UTC'));
    $this->artisan('comms:tick')->assertSuccessful();

    expect(CommsQuestion::query()->count())->toBe(2)
        ->and($intent->refresh()->state)->toBe('stale')
        ->and($watcher->refresh()->state)->toBe('cancelled')
        ->and(CommsEvent::query()->where('type', EventType::ClockDue)->count())->toBe(3);
});

test('a google play invitation settles the watcher without a question', function () {
    [$user, $intent] = phase3Waiting();
    $text = 'Invitation to Google Play';

    phase3Script([phase3Match([[
        'op' => 'attach',
        'intent_id' => $intent->key,
        'quote' => $text,
        'confidence' => 0.9,
    ]])]);
    phase3Say(
        $user,
        'g-play',
        $text,
        new Party('notifications@google.com', 'notifications@google.com', 'Google Play'),
        phase3At('2026-11-10T12:00:00Z'),
        source: Source::Gmail,
        channel: 'INBOX',
        thread: 'gmail-play',
    );

    $this->artisan('comms:tick')->assertSuccessful();

    expect(CommsWatcher::query()->whereBelongsTo($user)->sole()->state)->toBe('met')
        ->and(CommsQuestion::query()->count())->toBe(0)
        ->and(CommsEvent::query()->where('type', EventType::ClockDue)->count())->toBe(0);
});

test('an acknowledgement leaves the deadline and a delivery settles the watcher', function () {
    [$user, $intent, $watcher] = phase3Waiting(['flags' => ['production', 'client', 'money']], '2026-11-20');
    $deadline = $watcher->deadline_at->utc()->toIso8601String();

    phase3Script([
        phase3Heard(),
        phase3Match([[
            'op' => 'acknowledge',
            'intent_id' => $intent->key,
            'quote' => 'ok',
            'confidence' => 0.9,
        ]]),
    ]);
    phase3Say($user, 's-ok', 'ok', phase3Marta(), phase3At('2026-11-10T12:00:00Z'));

    expect($intent->refresh()->state)->toBe('open')
        ->and($intent->acknowledged_at)->not->toBeNull()
        ->and($watcher->refresh()->state)->toBe('active')
        ->and($watcher->deadline_at->utc()->toIso8601String())->toBe($deadline);

    phase3Script([
        phase3Heard(),
        phase3Match([[
            'op' => 'settle',
            'intent_id' => $intent->key,
            'quote' => 'hecho, ya tienes acceso',
            'reason' => 'delivery',
            'confidence' => 0.9,
        ]]),
    ]);
    phase3Say($user, 's-done', 'hecho, ya tienes acceso', phase3Marta(), phase3At('2026-11-10T13:00:00Z'));

    expect($intent->refresh()->state)->toBe('settled')
        ->and($intent->settle_reason)->toBe('delivery')
        ->and($watcher->refresh()->state)->toBe('met')
        ->and($watcher->deadline_at->utc()->toIso8601String())->toBe($deadline);
});

test('a low importance ask expires without a notification', function () {
    $user = User::factory()->create();

    CommsIntent::factory()->create([
        'user_id' => $user->id,
        'importance' => 10,
        'importance_override' => 10,
        'importance_override_until' => '2026-12-01 00:00:00',
        'deadline' => '2026-11-09',
        'state' => 'open',
        'flags' => [],
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 09:30:00', 'UTC'));
    $this->artisan('comms:tick')->assertSuccessful();

    expect(CommsIntent::query()->whereBelongsTo($user)->sole()->state)->toBe('expired')
        ->and(CommsNotification::query()->count())->toBe(0)
        ->and(CommsQuestion::query()->count())->toBe(0);
});

test('twelve stale intents produce one batch', function () {
    $user = User::factory()->create();

    CommsIntent::factory()->count(12)->create([
        'user_id' => $user->id,
        'importance' => 40,
        'deadline' => '2026-11-09',
        'state' => 'open',
        'flags' => [],
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 09:30:00', 'UTC'));
    $this->artisan('comms:tick')->assertSuccessful();

    expect(CommsIntent::query()->whereBelongsTo($user)->where('state', 'stale')->count())->toBe(12)
        ->and(CommsNotification::query()->where('kind', 'batch')->count())->toBe(1)
        ->and(CommsQuestion::query()->sole()->text)->toBe('¿Siguen abiertas?')
        ->and(CommsQuestion::query()->sole()->options)->toHaveCount(12);
});

test('two watchers due in the same minute append two clock events', function () {
    $user = User::factory()->create();

    foreach (['a', 'b'] as $key) {
        $intent = CommsIntent::factory()->create([
            'user_id' => $user->id,
            'flags' => [],
        ]);

        CommsWatcher::query()->create([
            'user_id' => $user->id,
            'intent_id' => $intent->id,
            'key' => $intent->key.':'.$key,
            'condition_type' => 'delivery',
            'condition' => ['reply' => true, 'delivery' => true],
            'deadline_at' => '2026-11-10 10:00:00',
            'attempt' => 0,
            'state' => 'active',
        ]);
    }

    $this->artisan('comms:tick')->assertSuccessful();
    $this->artisan('comms:tick')->assertSuccessful();

    expect(CommsEvent::query()->where('type', EventType::ClockDue)->count())->toBe(2);
});

test('a message that touches nothing produces no notification', function () {
    $user = phase3User();

    phase3Say(
        $user,
        'g-news',
        'The weekly digest',
        new Party('news@example.com', 'news@example.com', 'News'),
        phase3At('2026-11-10T11:00:00Z'),
        extra: ['headers' => ['List-Unsubscribe' => '<mailto:unsubscribe@example.com>']],
        source: Source::Gmail,
        channel: 'INBOX',
        thread: 'gmail-news',
    );

    expect(CommsIntent::query()->count())->toBe(0)
        ->and(CommsNotification::query()->count())->toBe(0);
});

test('a watcher without a date waits two business days', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-06 10:00:00', 'UTC'));
    [, , $watcher] = phase3Waiting(['flags' => ['production', 'client', 'money']], null, '2026-11-06T10:00:00Z');

    expect($watcher->deadline_at->utc()->toIso8601String())->toBe('2026-11-10T19:00:00+00:00');
});

test('replay rebuilds watchers and notifications', function () {
    [$user] = phase3Waiting();
    $this->artisan('comms:tick')->assertSuccessful();

    $notification = CommsNotification::query()->where('kind', 'question')->sole();

    $this->actingAs($user)->post(route('shadow.feedback', $notification), ['feedback' => 'not_urgent'])
        ->assertRedirect();

    $before = phase3Shadow($user);

    app(Replay::class)->run($user);

    expect(phase3Shadow($user))->toBe($before);
});

test('the shadow tab shows a question and keeps the mark', function () {
    [$user] = phase3Waiting();
    $this->artisan('comms:tick')->assertSuccessful();

    Http::preventStrayRequests(false);

    $this->actingAs($user)->get(route('shadow.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('shadow')
            ->where('items.0.text', CommsQuestion::query()->sole()->text)
            ->where('items.0.urgency', 'digest'));
});

/**
 * @param  array<string, mixed>  $extra
 * @return array{0: User, 1: CommsIntent, 2: CommsWatcher}
 */
function phase3Waiting(array $extra = [], ?string $deadline = '2026-11-09', string $at = '2026-11-10T11:00:00Z'): array
{
    $user = phase3User();

    $marta = CommsPerson::query()->create([
        'user_id' => $user->id,
        'display_name' => 'Marta',
        'relation' => Relation::Collaborator,
        'importance_weight' => Relation::Collaborator->weight(),
        'is_bot' => false,
    ]);
    CommsIdentity::query()->create([
        'user_id' => $user->id,
        'person_id' => $marta->id,
        'source' => Source::Slack,
        'external_id' => 'U-marta',
        'email' => 'marta@example.com',
        'handle' => 'marta',
    ]);

    $text = '¿Me pasas la invitación de Google Play?';
    $open = phase3Open('Conseguir la invitación de Google Play', $text, ['self'], ['slack:U-marta'], array_merge([
        'expects' => 'la invitación de Google Play',
        'flags' => ['production'],
    ], $deadline === null ? [] : ['deadline' => $deadline], $extra));

    phase3Script([phase3Heard(), phase3Match([$open])]);
    phase3Say($user, 's-ask', $text, phase3Me(), phase3At($at), direction: Direction::Outgoing, recipients: [phase3Marta()]);

    $intent = CommsIntent::query()->whereBelongsTo($user)->firstOrFail();
    $watcher = CommsWatcher::query()->whereBelongsTo($user)->firstOrFail();

    expect($intent->importance)->toBeGreaterThanOrEqual(60);

    return [$user, $intent, $watcher];
}

/**
 * @return array<string, mixed>
 */
function phase3Shadow(User $user): array
{
    return [
        'watchers' => CommsWatcher::query()->whereBelongsTo($user)->orderBy('key')->get()->map(fn (CommsWatcher $watcher): array => [
            'key' => $watcher->key,
            'state' => $watcher->state,
            'attempt' => $watcher->attempt,
            'deadline' => $watcher->deadline_at?->utc()->toIso8601String(),
        ])->all(),
        'questions' => CommsQuestion::query()->whereBelongsTo($user)->orderBy('key')->get()->map(fn (CommsQuestion $question): array => [
            'key' => $question->key,
            'text' => $question->text,
        ])->all(),
        'notifications' => CommsNotification::query()->whereBelongsTo($user)->orderBy('key')->get()->map(fn (CommsNotification $notification): array => [
            'key' => $notification->key,
            'kind' => $notification->kind,
            'urgency' => $notification->urgency,
            'feedback' => $notification->feedback,
        ])->all(),
        'intent' => CommsIntent::query()->whereBelongsTo($user)->orderBy('key')->get()->map(fn (CommsIntent $intent): array => [
            'key' => $intent->key,
            'state' => $intent->state,
        ])->all(),
    ];
}

function phase3User(): User
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

function phase3Marta(): Party
{
    return new Party('U-marta', 'marta@example.com', 'Marta');
}

function phase3Me(): Party
{
    return new Party('U-me', 'me@example.com', 'Nicolás');
}

function phase3At(string $moment): CarbonImmutable
{
    return CarbonImmutable::parse(strlen($moment) === 10 ? $moment.'T10:00:00Z' : $moment);
}

/**
 * @param  list<array<string, mixed>>  $bodies
 */
function phase3Script(array $bodies): FakeCompleter
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
 * @return array{label: string, urgent: bool, sensitive: bool, confidence: float}
 */
function phase3Heard(): array
{
    return [
        'label' => 'possible_intent',
        'urgent' => false,
        'sensitive' => false,
        'confidence' => 0.9,
    ];
}

/**
 * @param  list<array<string, mixed>>  $operations
 * @return array{operations: list<array<string, mixed>>, confidence: float}
 */
function phase3Match(array $operations): array
{
    return ['operations' => $operations, 'confidence' => 0.9];
}

/**
 * @param  list<string>  $requesters
 * @param  list<string>  $responders
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function phase3Open(string $what, string $quote, array $requesters, array $responders, array $extra = []): array
{
    return array_merge([
        'op' => 'open',
        'what' => $what,
        'quote' => $quote,
        'confidence' => 0.9,
        'requesters' => $requesters,
        'responders' => $responders,
    ], $extra);
}

/**
 * @param  array<string, mixed>  $extra
 * @param  list<Party>  $recipients
 */
function phase3Say(
    User $user,
    string $id,
    string $text,
    Party $author,
    CarbonImmutable $at,
    array $extra = [],
    Source $source = Source::Slack,
    Direction $direction = Direction::Incoming,
    string $channel = 'C1',
    string $thread = 'T1',
    array $recipients = [],
): CommsEvent {
    $message = new Message(
        externalId: $id,
        source: $source,
        channel: $channel,
        thread: $thread,
        author: $author,
        recipients: $recipients,
        direction: $direction,
        at: $at,
        text: $text,
    );

    return app(EventLog::class)->append(
        user: $user,
        type: $direction === Direction::Outgoing ? EventType::MessageSent : EventType::MessageReceived,
        source: $source,
        externalId: $id,
        payload: null,
        normalized: array_merge($message->toArray(), $extra),
        causation: null,
        occurredAt: $at,
    );
}
