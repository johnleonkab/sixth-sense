<?php

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Gate\ActionClass;
use App\Comms\Gate\Gate;
use App\Comms\Gate\ProposalStatus;
use App\Comms\Ingestion\IdentityResolver;
use App\Comms\Ingestion\SourceIngestor;
use App\Comms\Messages\Direction;
use App\Comms\Messages\Message;
use App\Comms\Messages\Party;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Comms\Watchers\Clock;
use App\Connections\Provider;
use App\Models\Comms\CommsCalendarEvent;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsPlaybookRun;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\Comms\CommsWatcher;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('approved work waits for the page and then asks without a model', function () {
    Carbon::setTestNow('2026-11-08 12:00:00');
    $user = phase6User();
    $text = 'Necesito el dato de prepago para el lunes.';

    phase6Script([
        phase6Heard(),
        phase6Match([phase6Open('Preparar el dato de prepago', $text, ['slack:U-marta'], ['self'], [
            'kind' => 'prepare',
            'deadline' => '2026-11-10',
        ])]),
        ['band' => 30, 'reason' => 'Hay que sacarlo de la hoja.', 'underspecified' => false],
    ]);
    phase6Say($user, 's-3', $text, phase6Marta(), phase6At('2026-11-08T12:00:00Z'));

    $run = CommsPlaybookRun::query()->sole();

    expect($run->state)->toBe('awaiting_approval')
        ->and(CommsProposal::query()->where('action_class', ActionClass::CalendarCreate)->count())->toBe(1);

    $page = CommsProposal::query()->where('action_class', ActionClass::NotionCreatePage)->sole();
    app(Gate::class)->approve($page);

    expect($run->refresh()->state)->toBe('waiting')
        ->and($run->step)->toBe('page');

    $decisions = CommsEvent::query()->where('type', EventType::ModelDecided)->count();

    app(SourceIngestor::class)->changed(
        $user,
        Source::Notion,
        'page:'.$run->id,
        'page_edited',
        'notion:'.$run->intent?->key,
        'La página ya tiene el dato.',
        phase6At('2026-11-09T10:00:00Z'),
    );

    expect(CommsEvent::query()->where('type', EventType::ModelDecided)->count())->toBe($decisions)
        ->and(CommsQuestion::query()->sole()->text)->toBe('¿Hecho? ¿Lo envío?');
});

test('a meeting with nothing open stays quiet', function () {
    Carbon::setTestNow('2026-11-09 11:00:00');
    $user = phase6User();

    CommsCalendarEvent::query()->create([
        'user_id' => $user->id,
        'external_id' => 'cal:planning',
        'title' => 'Weekly',
        'kind' => 'meeting',
        'starts_at' => CarbonImmutable::parse('2026-11-09 11:15:00'),
        'ends_at' => CarbonImmutable::parse('2026-11-09 11:45:00'),
        'attendees' => ['Marta'],
        'status' => 'confirmed',
    ]);

    app(Clock::class)->append(CarbonImmutable::now());

    expect(CommsNotification::query()->count())->toBe(0)
        ->and(CommsQuestion::query()->count())->toBe(0);
});

test('a transcript opens commitments and records a contradiction', function () {
    $user = phase6User();

    phase6Script([phase6Noise()]);
    phase6Say(
        $user,
        'g-after',
        'El tope queda en 10.',
        new Party('marta@example.com', 'marta@example.com', 'Marta'),
        phase6At('2026-11-08'),
        Source::Gmail,
        'INBOX',
    );

    phase6Script([
        ['actions' => [
            ['owner' => 'self', 'what' => 'Enviar el informe', 'due' => '2026-11-12', 'quote' => 'El tope queda en 50'],
            ['owner' => 'Marta', 'what' => 'Revisar el diseño', 'due' => '2026-11-12', 'quote' => 'Marta revisa el diseño'],
        ]],
        ['findings' => [['summary' => 'No coincide: El tope queda en 50 / El tope queda en 10']]],
    ]);
    app(SourceIngestor::class)->changed(
        $user,
        Source::Drive,
        'transcript:1',
        'transcript',
        'drive:notes',
        'El tope queda en 50. Marta revisa el diseño.',
        phase6At('2026-11-08T18:00:00Z'),
    );

    expect(CommsIntent::query()->count())->toBe(2)
        ->and(CommsWatcher::query()->count())->toBe(1)
        ->and(CommsQuestion::query()->sole()->text)->toContain('No coincide');
});

test('no free slot proposes asking for more time', function () {
    Carbon::setTestNow('2026-11-08 12:00:00');
    $user = phase6User();
    $text = 'Mándame el informe hoy.';

    phase6Script([
        phase6Heard(),
        phase6Match([phase6Open('Enviar el informe', $text, ['slack:U-marta'], ['self'], [
            'kind' => 'prepare',
            'deadline' => '2026-11-08',
        ])]),
        ['band' => 60, 'reason' => 'Hay que montarlo.', 'underspecified' => false],
    ]);
    phase6Say($user, 's-8', $text, phase6Marta(), phase6At('2026-11-08T12:00:00Z'));

    expect(CommsProposal::query()->where('action_class', ActionClass::CalendarCreate)->count())->toBe(0)
        ->and(CommsProposal::query()->sole()->payload['text'])->toContain('más tiempo');
});

test('a calendar event is written only after approval and can be deleted', function () {
    $user = phase6User();
    $intent = CommsIntent::factory()->for($user)->create();
    $proposal = app(Gate::class)->submit(
        user: $user,
        class: ActionClass::CalendarCreate,
        target: ['ref' => 'self'],
        payload: ['text' => 'Bloque de prepago', 'title' => 'Bloque de prepago', 'starts_at' => '2026-11-09T09:00:00Z', 'ends_at' => '2026-11-09T09:30:00Z'],
        intent: $intent,
    );

    expect($proposal->external_id)->toBeNull()
        ->and(CommsEvent::query()->where('type', EventType::ActionExecuted)->count())->toBe(0);

    app(Gate::class)->approve($proposal->refresh());

    expect($proposal->refresh()->external_id)->toStartWith('calendar:');

    $undone = app(Gate::class)->undo($proposal->refresh());

    expect($undone->status)->toBe(ProposalStatus::Undone)
        ->and($undone->payload['deleted'])->toBeTrue()
        ->and(CommsEvent::query()->where('type', EventType::ActionUndone)->count())->toBe(1);
});

test('interview feedback reminds only the user', function () {
    Carbon::setTestNow('2026-11-09 11:00:00');
    $user = phase6User();

    CommsCalendarEvent::query()->create([
        'user_id' => $user->id,
        'external_id' => 'cal:interview',
        'title' => 'Entrevista',
        'kind' => 'interview',
        'starts_at' => CarbonImmutable::now()->subHours(3),
        'ends_at' => CarbonImmutable::now()->subHours(2),
        'attendees' => ['Recruiter'],
        'status' => 'confirmed',
    ]);

    app(Clock::class)->append(CarbonImmutable::now());

    expect(CommsProposal::query()->count())->toBe(0)
        ->and(CommsQuestion::query()->sole()->text)->toContain('Solo para ti');
});

function phase6User(): User
{
    $user = User::factory()->create(['name' => 'Nicolás']);

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

function phase6Marta(): Party
{
    return new Party('U-marta', 'marta@example.com', 'Marta');
}

function phase6At(string $moment): CarbonImmutable
{
    return CarbonImmutable::parse(strlen($moment) === 10 ? $moment.'T10:00:00Z' : $moment);
}

/**
 * @param  list<array<string, mixed>>  $bodies
 */
function phase6Script(array $bodies): void
{
    $fake = new FakeCompleter;
    $fake->script = array_map(
        fn (array $body): ModelCompletion => new ModelCompletion($body, 'scripted'),
        $bodies,
    );
    app()->instance(CompletesTasks::class, $fake);
}

/**
 * @return array<string, mixed>
 */
function phase6Heard(): array
{
    return ['label' => 'possible_intent', 'urgent' => false, 'sensitive' => false, 'confidence' => 0.9];
}

/**
 * @return array<string, mixed>
 */
function phase6Noise(): array
{
    return ['label' => 'noise', 'urgent' => false, 'sensitive' => false, 'confidence' => 0.9];
}

/**
 * @param  list<array<string, mixed>>  $operations
 * @return array<string, mixed>
 */
function phase6Match(array $operations): array
{
    return ['operations' => $operations, 'confidence' => 0.9];
}

/**
 * @param  list<string>  $requesters
 * @param  list<string>  $responders
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function phase6Open(string $what, string $quote, array $requesters, array $responders, array $extra = []): array
{
    return [...$extra, 'op' => 'open', 'what' => $what, 'quote' => $quote, 'confidence' => 0.9, 'requesters' => $requesters, 'responders' => $responders];
}

function phase6Say(
    User $user,
    string $id,
    string $text,
    Party $author,
    CarbonImmutable $at,
    Source $source = Source::Slack,
    string $channel = 'C1',
): CommsEvent {
    $message = new Message(
        externalId: $id,
        source: $source,
        channel: $channel,
        thread: $channel,
        author: $author,
        recipients: [],
        direction: Direction::Incoming,
        at: $at,
        text: $text,
    );

    return app(EventLog::class)->append(
        user: $user,
        type: EventType::MessageReceived,
        source: $source,
        externalId: $id,
        payload: null,
        normalized: $message->toArray(),
        causation: null,
        occurredAt: $at,
    );
}
