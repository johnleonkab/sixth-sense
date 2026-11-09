<?php

use App\Ai\Schema;
use App\Comms\Evaluation\FixtureRunner;
use App\Comms\Evaluation\ProjectionMismatch;
use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Replay;
use App\Comms\Events\Source;
use App\Comms\Messages\Direction;
use App\Comms\Messages\Message;
use App\Comms\Messages\Party;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelAnswerKind;
use App\Comms\Models\ModelCompletion;
use App\Comms\Models\ModelRouter;
use App\Models\AiUsage;
use App\Models\Comms\CommsEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

test('replaying a stored sequence twice leaves the same rows', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $log = app(EventLog::class);
    $first = CarbonImmutable::parse('2026-11-08T09:00:00Z');
    $second = $first->addHour();

    $log->append($user, EventType::MessageReceived, Source::Slack, 'slack-1', ['raw' => 'a'], ['text' => 'a'], null, $first);
    $log->append($user, EventType::MessageReceived, Source::Gmail, 'gmail-1', ['raw' => 'b'], ['text' => 'b'], null, $second);
    $log->append($user, EventType::MessageReceived, Source::Slack, 'slack-1', ['raw' => 'again'], ['text' => 'again'], null, $first);

    expect(CommsEvent::query()->count())->toBe(2);

    $rows = CommsEvent::query()->orderBy('id')->pluck('id')->all();

    $this->artisan('comms:replay', ['user' => $user->id])->assertSuccessful();
    $this->artisan('comms:replay', ['user' => $user->id])->assertSuccessful();

    expect(CommsEvent::query()->orderBy('id')->pluck('id')->all())->toBe($rows)
        ->and(app(Replay::class)->run($user, $second))->toBe(1);
});

test('replay does not call a model', function () {
    $user = User::factory()->create();

    app(EventLog::class)->append(
        $user,
        EventType::ClockDue,
        Source::Clock,
        null,
        null,
        null,
        null,
        CarbonImmutable::parse('2026-11-08T18:00:00Z'),
    );

    app()->bind(CompletesTasks::class, fn () => throw new RuntimeException('The replay called a model.'));

    $this->artisan('comms:replay', ['user' => $user->id, '--rejudge' => true])->assertSuccessful();

    expect(CommsEvent::query()->count())->toBe(1);
});

test('a stored message keeps its normalized text', function () {
    $user = User::factory()->create();
    $message = new Message(
        externalId: 'slack-9',
        source: Source::Slack,
        channel: 'C1',
        thread: '1728290000.1234',
        author: new Party('U1', null, 'U1'),
        recipients: [new Party('U2', null, 'U2')],
        direction: Direction::Incoming,
        at: CarbonImmutable::parse('2026-11-08T09:30:00Z'),
        text: 'Can you review the prepaid flow before Friday?',
        links: ['https://example.test/flow'],
    );

    $stored = app(EventLog::class)->appendMessage($user, $message, ['raw' => true]);

    expect($stored->type)->toBe(EventType::MessageReceived)
        ->and($stored->normalized['text'])->toBe('Can you review the prepaid flow before Friday?')
        ->and(Message::fromArray($stored->normalized)->thread)->toBe('1728290000.1234');
});

test('an event cannot be changed or deleted', function () {
    $event = CommsEvent::factory()->create();

    expect(fn () => $event->save())->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});

test('a fixture replays twice to the same rows', function () {
    $user = User::factory()->create();
    $runner = app(FixtureRunner::class);
    $path = base_path('tests/Fixtures/Comms/phase-0-sequence.php');

    $runner->check($user, $path);
    $rows = CommsEvent::query()->orderBy('id')->pluck('id')->all();
    $runner->check($user, $path);

    expect(CommsEvent::query()->orderBy('id')->pluck('id')->all())->toBe($rows);
});

test('a fixture fails when the projections differ', function () {
    $user = User::factory()->create();

    expect(fn () => app(FixtureRunner::class)->check($user, base_path('tests/Fixtures/Comms/phase-0-mismatch.php')))
        ->toThrow(ProjectionMismatch::class);
});

test('a schema failure is retried once, escalated once, and then recorded as a question', function () {
    Http::preventStrayRequests();

    config(['comms.tasks.triage.tier' => 'mid']);

    $fake = scripted([
        new ModelCompletion(['label' => 1], 'mid-model'),
        new ModelCompletion(['label' => 1], 'mid-model'),
        new ModelCompletion(['label' => 1], 'large-model'),
        new ModelCompletion(['label' => 1], 'large-model'),
    ]);
    $user = User::factory()->create();

    $answer = app(ModelRouter::class)->ask($user, 'triage', 'A message arrived.', labelSchema(), []);

    expect($answer->kind)->toBe(ModelAnswerKind::Question)
        ->and($answer->event->type)->toBe(EventType::Question)
        ->and($answer->event->normalized['reason'])->toBe('schema')
        ->and(array_column($fake->calls, 'tier'))->toBe(['mid', 'mid', 'large', 'large'])
        ->and($fake->calls[2]['input'])->toContain('Previous attempt')
        ->and(AiUsage::query()->where('status', 'invalid')->count())->toBe(4);
});

test('a low confidence answer is escalated with that answer as the hint', function () {
    Http::preventStrayRequests();

    $fake = scripted([
        new ModelCompletion(['label' => 'maybe', 'confidence' => 0.2], 'small-model'),
        new ModelCompletion(['label' => 'sure', 'confidence' => 0.9], 'mid-model'),
    ]);
    $user = User::factory()->create();
    $schema = Schema::object([
        'label' => Schema::string(),
        'confidence' => Schema::number(),
    ]);

    $answer = app(ModelRouter::class)->ask($user, 'triage', 'A message arrived.', $schema, []);

    expect($answer->kind)->toBe(ModelAnswerKind::Accepted)
        ->and($answer->output['label'])->toBe('sure')
        ->and(array_column($fake->calls, 'tier'))->toBe(['small', 'mid'])
        ->and($fake->calls[1]['input'])->toContain('0.2')
        ->and(CommsEvent::query()->where('type', EventType::ModelDecided)->count())->toBe(1);
});

test('a provider failure uses the fallback provider', function () {
    Http::preventStrayRequests();

    config([
        'comms.tasks.triage.provider' => 'deepseek',
        'comms.tasks.triage.fallback' => 'gemini',
    ]);

    $fake = scripted([
        new ModelCompletion(['label' => 'noise'], 'gemini-model'),
    ]);
    $fake->failures = ['deepseek'];
    $user = User::factory()->create();

    $answer = app(ModelRouter::class)->ask($user, 'triage', 'Newsletter.', labelSchema(), []);

    expect($answer->kind)->toBe(ModelAnswerKind::Accepted)
        ->and(array_column($fake->calls, 'provider'))->toBe(['deepseek', 'gemini']);
});

test('a spent budget records a no-draft marker and does not call a model', function () {
    Http::preventStrayRequests();

    config(['comms.budgets.per_day' => 100]);

    $user = User::factory()->create();
    AiUsage::query()->create([
        'user_id' => $user->id,
        'task' => 'triage',
        'provider' => 'deepseek',
        'model' => 'small-model',
        'status' => 'ok',
        'input_tokens' => 100,
        'output_tokens' => 0,
    ]);
    $fake = scripted([
        new ModelCompletion(['label' => 'noise'], 'small-model'),
    ]);

    $answer = app(ModelRouter::class)->ask($user, 'triage', 'A message arrived.', labelSchema(), []);

    expect($fake->calls)->toBe([])
        ->and($answer->kind)->toBe(ModelAnswerKind::NoDraft)
        ->and($answer->event->type)->toBe(EventType::NoDraft)
        ->and(AiUsage::query()->where('status', 'budget')->count())->toBe(1);
});

test('a message over its token budget records a no-draft marker', function () {
    Http::preventStrayRequests();

    config(['comms.budgets.per_message' => 30]);

    $user = User::factory()->create();
    $cause = CommsEvent::factory()->for($user)->create();
    AiUsage::query()->create([
        'user_id' => $user->id,
        'task' => 'triage',
        'provider' => 'deepseek',
        'model' => 'small-model',
        'status' => 'ok',
        'input_tokens' => 30,
        'output_tokens' => 0,
        'event_ids' => [$cause->id],
    ]);
    $fake = scripted([
        new ModelCompletion(['label' => 'noise'], 'small-model'),
    ]);

    $answer = app(ModelRouter::class)->ask($user, 'triage', 'A message arrived.', labelSchema(), [$cause->id]);

    expect($fake->calls)->toBe([])
        ->and($answer->kind)->toBe(ModelAnswerKind::NoDraft);
});

test('a sensitive call stores the decision and omits the model output from the usage log', function () {
    Http::preventStrayRequests();

    scripted([
        new ModelCompletion(['label' => 'intent'], 'small-model', inputTokens: 12, outputTokens: 4),
    ]);
    $user = User::factory()->create();
    $cause = CommsEvent::factory()->for($user)->create();

    $answer = app(ModelRouter::class)->ask($user, 'triage', 'A private note.', labelSchema(), [$cause->id], sensitive: true);

    $usage = AiUsage::query()->where('status', 'ok')->sole();

    expect($answer->kind)->toBe(ModelAnswerKind::Accepted)
        ->and($answer->event->normalized['output']['label'])->toBe('intent')
        ->and($answer->event->causation_id)->toBe($cause->id)
        ->and($usage->output)->toBeNull()
        ->and($usage->event_ids)->toBe([$cause->id])
        ->and($usage->prompt_version)->toBe(1)
        ->and($usage->tier)->toBe('small')
        ->and($usage->input_tokens)->toBe(12);
});

test('the communications pipeline does not reference connectors', function () {
    $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Comms')));

    foreach ($paths as $path) {
        if (! $path->isFile() || $path->getExtension() !== 'php') {
            continue;
        }

        // Ingestion is the read boundary. Write tools are the write boundary.
        if (str_contains($path->getPathname(), DIRECTORY_SEPARATOR.'Ingestion'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        if (str_contains($path->getPathname(), DIRECTORY_SEPARATOR.'WriteTools'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        // Cutover translates an inbox candidate into an inbound message.
        if (str_contains($path->getPathname(), DIRECTORY_SEPARATOR.'Cutover'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        expect(file_get_contents($path->getPathname()))->not->toContain('App\\Connectors');
    }
});

/**
 * @param  list<ModelCompletion>  $script
 */
function scripted(array $script): FakeCompleter
{
    $fake = new FakeCompleter;
    $fake->script = $script;
    app()->instance(CompletesTasks::class, $fake);

    return $fake;
}

/**
 * @return array<string, mixed>
 */
function labelSchema(): array
{
    return Schema::object([
        'label' => Schema::string(),
    ]);
}
