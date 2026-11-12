<?php

use App\Comms\Context\ContextEngine;
use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Replay;
use App\Comms\Events\Source;
use App\Comms\Gate\ActionClass;
use App\Comms\Ingestion\IdentityResolver;
use App\Comms\Ingestion\SourceIngestor;
use App\Comms\Messages\Direction;
use App\Comms\Messages\Message;
use App\Comms\Messages\Party;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Comms\People\Relation;
use App\Comms\Playbooks\AnswerFromSources;
use App\Connections\Provider;
use App\Knowledge\KnowledgeSource;
use App\Models\AiUsage;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIdentity;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsOwnedArea;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\ConnectedAccount;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('a jira comment becomes one cited reply and an open question', function () {
    $user = phase5User();
    $text = 'El prepago espera tres días. El tope es 50 (jira:PAY-1). ¿Y la fecha de corte?';

    phase5Script([
        phase5Heard(),
        phase5Match([phase5Open('Responder el comentario de prepago', $text, ['jira:dev-1'], ['self'], ['kind' => 'question'])]),
        phase5Points([
            ['text' => 'El prepago espera tres días. El tope es 50 (jira:PAY-1).', 'refs' => ['jira:PAY-1'], 'unknown' => false],
            ['text' => '', 'refs' => [], 'unknown' => true, 'found' => 'la fecha de corte'],
        ]),
        phase5Keep(['El tope es 50 (jira:PAY-1).']),
    ]);
    phase5Say($user, 'c-1', $text, new Party('dev-1', 'dev@example.com', 'Diego'), phase5At('2026-11-08'), Source::Jira, 'PAY-1');

    $proposal = CommsProposal::query()->sole();

    expect($proposal->action_class)->toBe(ActionClass::JiraComment)
        ->and($proposal->payload['text'])->toBe('El tope es 50 (jira:PAY-1).')
        ->and($proposal->payload['text'])->not->toContain('tres días')
        ->and(CommsQuestion::query()->sole()->text)->toContain('fecha de corte');

    app(Replay::class)->run($user);

    expect(CommsProposal::query()->count())->toBe(1)
        ->and(CommsProposal::query()->sole()->payload['text'])->toBe('El tope es 50 (jira:PAY-1).')
        ->and(CommsQuestion::query()->sole()->text)->toContain('fecha de corte');
});

test('a product question names a disagreement between code and spec', function () {
    $user = phase5User();
    $text = '¿Cómo está el prepago?';
    phase5Doc($user, 'gh:app@1:Pay.php#L1-L4', 'code', 'prepago en código', 'El tope es 10.', 'prepaid');
    phase5Doc($user, 'notion:spec', 'notion', 'prepago en la spec', 'El tope es 50.', 'prepaid');
    $conflict = 'El código y la spec no coinciden: gh:app@1:Pay.php#L1-L4 notion:spec.';
    $cited = 'El tope documentado es 50 (jira:PAY-9).';

    phase5Script([
        phase5Heard(),
        phase5Match([phase5Open('Explicar el prepago', $text, ['slack:U-marta'], ['self'], ['kind' => 'question'])]),
        phase5Points([['text' => $cited, 'refs' => ['jira:PAY-9'], 'unknown' => false]]),
        phase5Keep([$cited, $conflict]),
    ]);
    phase5Say($user, 's-9', $text, phase5Marta(), phase5At('2026-11-08'));

    expect(CommsProposal::query()->sole()->payload['text'])->toContain('no coinciden')
        ->and(CommsProposal::query()->sole()->payload['text'])->toContain('gh:app@1:Pay.php#L1-L4')
        ->and(CommsProposal::query()->sole()->payload['text'])->toContain('notion:spec');
});

test('a question that was already answered is a link and one sentence', function () {
    $user = phase5User();
    $text = 'Cómo funciona el prepago';
    phase5Doc($user, 'notion:prepago', 'notion', $text, 'Está respondido en la página.', null);

    phase5Script([
        phase5Heard(),
        phase5Match([phase5Open($text, $text, ['slack:U-marta'], ['self'], ['kind' => 'question'])]),
        phase5Keep(['notion:prepago Está respondido en la página.']),
    ]);
    phase5Say($user, 's-19', $text, phase5Marta(), phase5At('2026-11-08'));

    expect(CommsProposal::query()->sole()->payload['text'])->toBe('notion:prepago Está respondido en la página.');
});

test('a choice is shown as tradeoffs and sent only after the user picks', function () {
    $user = phase5User();
    $text = '¿Vamos con A o con B?';

    phase5Script([
        phase5Heard(),
        phase5Match([phase5Open('Elegir entre A y B', $text, ['slack:U-marta'], ['self'], ['kind' => 'choice'])]),
        phase5Points([
            ['text' => 'A cuesta un día (jira:PAY-A).', 'refs' => ['jira:PAY-A'], 'unknown' => false],
            ['text' => 'B cuesta una semana (notion:spec-b).', 'refs' => ['notion:spec-b'], 'unknown' => false],
        ]),
    ]);
    phase5Say($user, 's-23', $text, phase5Marta(), phase5At('2026-11-08'));

    $question = CommsQuestion::query()->sole();

    expect(CommsProposal::query()->count())->toBe(0)
        ->and($question->text)->toContain('jira:PAY-A')
        ->and($question->text)->toContain('notion:spec-b');

    app(EventLog::class)->append(
        user: $user,
        type: EventType::UserAnswered,
        source: Source::User,
        externalId: 'choice:'.$question->key,
        payload: null,
        normalized: ['kind' => 'choice', 'question_key' => $question->key, 'text' => 'Vamos con A.'],
        causation: null,
        occurredAt: phase5At('2026-11-08T11:00:00Z'),
    );

    expect(CommsProposal::query()->sole()->payload['text'])->toBe('Vamos con A.');
});

test('a thin ticket proposes a jira comment listing what is missing', function () {
    $user = phase5User();
    $text = 'Hay que mirar el prepago.';

    phase5Script([
        phase5Heard(),
        phase5Match([phase5Open('Completar el ticket', $text, ['jira:dev-1'], ['self'], ['kind' => 'review_comment'])]),
        phase5Points([['text' => 'Faltan criterios de aceptación (jira:PAY-37).', 'refs' => ['jira:PAY-37'], 'unknown' => false]]),
        phase5Keep(['Faltan criterios de aceptación (jira:PAY-37).']),
    ]);
    phase5Say($user, 'c-37', $text, new Party('dev-1', 'dev@example.com', 'Diego'), phase5At('2026-11-08'), Source::Jira, 'PAY-37');

    $proposal = CommsProposal::query()->sole();

    expect($proposal->action_class)->toBe(ActionClass::JiraComment)
        ->and($proposal->payload['text'])->toContain('criterios de aceptación');
});

test('a sourced reply without inline refs still becomes a proposal', function () {
    $user = phase5User();
    $text = '¿Cuál es el tope de prepago?';
    $draft = 'El tope es 50 euros.';

    phase5Script([
        phase5Heard(),
        phase5Match([phase5Open('Responder el tope de prepago', $text, ['slack:U-marta'], ['self'], ['kind' => 'question'])]),
        phase5Points([['text' => $draft, 'refs' => ['jira:PAY-1'], 'unknown' => false]]),
        phase5Keep([$draft]),
    ]);
    phase5Say($user, 's-cite', $text, phase5Marta(), phase5At('2026-11-09T12:00:00Z'));

    expect(CommsProposal::query()->sole()->payload['text'])->toBe($draft)
        ->and(CommsQuestion::query()->count())->toBe(0)
        ->and(CommsNotification::query()->where('kind', 'question')->count())->toBe(0);
});

test('a generic unknown stays quiet instead of pushing an empty draft question', function () {
    $user = phase5User();
    $text = '¿Cuál es la fecha de corte?';

    phase5Script([
        phase5Heard(),
        phase5Match([phase5Open('Responder la fecha de corte', $text, ['slack:U-marta'], ['self'], ['kind' => 'question'])]),
        phase5Points([['text' => 'No se encontró información en las fuentes.', 'refs' => [], 'unknown' => true]]),
    ]);
    phase5Say($user, 's-empty', $text, phase5Marta(), phase5At('2026-11-09T12:00:00Z'));

    expect(CommsProposal::query()->count())->toBe(0)
        ->and(CommsQuestion::query()->count())->toBe(0)
        ->and(CommsNotification::query()->where('kind', 'question')->count())->toBe(0);
});

test('a compliance ask stays a draft', function () {
    $user = phase5User();
    CommsOwnedArea::query()->create([
        'user_id' => $user->id,
        'kind' => 'compliance',
        'ref' => 'legal',
        'name' => 'Legal',
        'keywords' => [],
    ]);
    $text = '¿Guardamos el dato de la tarjeta?';

    phase5Script([
        phase5Heard(),
        phase5Match([phase5Open('Responder a legal', $text, ['slack:U-marta'], ['self'], ['kind' => 'question'])]),
        phase5Points([['text' => 'No se guarda el número (notion:privacy).', 'refs' => ['notion:privacy'], 'unknown' => false]]),
    ]);
    phase5Say($user, 's-60', $text, phase5Marta(), phase5At('2026-11-08'));

    expect(CommsProposal::query()->count())->toBe(0)
        ->and(CommsQuestion::query()->sole()->text)->toBe('Borrador para revisar, no se envía.')
        ->and(CommsQuestion::query()->sole()->context['draft'])->toContain('notion:privacy');
});

test('a late reply is a summary the user can answer on the same channel', function () {
    $user = phase5User();
    phase5Person($user);
    $asked = '¿Me das acceso al panel?';

    phase5Script([
        phase5Heard(),
        phase5Match([phase5Open('Conseguir acceso al panel', $asked, ['self'], ['slack:U-marta'])]),
    ]);
    phase5Say($user, 's-me', $asked, phase5Me(), phase5At('2026-11-01'), direction: Direction::Outgoing);

    $intent = CommsIntent::query()->sole();

    phase5Script([
        phase5Heard(),
        phase5Match([['op' => 'attach', 'intent_id' => $intent->key, 'quote' => 'Ya lo tienes', 'confidence' => 0.9]]),
        ['summary' => 'Pediste acceso al panel porque hacía falta. Ahora dicen que ya lo tienes.'],
    ]);
    phase5Say($user, 's-late', 'Ya lo tienes', phase5Marta(), phase5At('2026-11-06'));

    expect(CommsQuestion::query()->sole()->text)->toContain('Pediste acceso al panel')
        ->and(CommsQuestion::query()->sole()->context['draft'])->toBe('');
});

test('an insisted ask is proposed on the channel of the latest message', function () {
    $user = phase5User();
    $ask = '¿Puedes revisar el prepago?';
    $cited = 'El tope es 50 (jira:PAY-10).';

    phase5Script([
        phase5Heard(),
        phase5Match([phase5Open('Revisar el prepago', $ask, ['gmail:marta@example.com'], ['self'], ['kind' => 'question'])]),
        phase5Points([['text' => $cited, 'refs' => ['jira:PAY-10'], 'unknown' => false]]),
        phase5Keep([$cited]),
    ]);
    phase5Say($user, 'g-10', $ask, new Party('marta@example.com', 'marta@example.com', 'Marta'), phase5At('2026-11-08'), Source::Gmail, 'INBOX');

    $intent = CommsIntent::query()->sole();

    phase5Script([
        phase5Heard(),
        phase5Match([['op' => 'attach', 'intent_id' => $intent->key, 'quote' => 'Te lo pido por aquí también', 'confidence' => 0.9]]),
        phase5Points([['text' => $cited, 'refs' => ['jira:PAY-10'], 'unknown' => false]]),
        phase5Keep([$cited]),
    ]);
    phase5Say($user, 's-10', 'Te lo pido por aquí también', phase5Marta(), phase5At('2026-11-08T12:00:00Z'), channel: 'C9');

    $latest = CommsProposal::query()->orderByDesc('id')->first();

    expect($latest->action_class)->toBe(ActionClass::SendSlack)
        ->and($latest->target['channel'])->toBe('C9');
});

test('a copied message about an owned area is a digest signal and not an intent', function () {
    $user = phase5User();
    CommsOwnedArea::query()->create([
        'user_id' => $user->id,
        'kind' => 'product',
        'ref' => 'prepago',
        'name' => 'Prepago',
        'keywords' => ['prepago'],
    ]);

    phase5Say(
        $user,
        'g-cc',
        'Actualización del prepago para el equipo.',
        new Party('luis@example.com', 'luis@example.com', 'Luis'),
        phase5At('2026-11-08'),
        Source::Gmail,
        'INBOX',
        extra: ['cc' => [['email' => 'me@example.com']]],
    );

    expect(CommsIntent::query()->count())->toBe(0)
        ->and(CommsNotification::query()->sole()->kind)->toBe('signal')
        ->and(CommsNotification::query()->sole()->urgency)->toBe('digest');
});

test('a spent token budget stops with a no-draft notification', function () {
    config(['comms.budgets.per_message' => 10]);
    $user = phase5User();
    $intent = CommsIntent::factory()->for($user)->create(['flags' => ['kind' => 'question'], 'what' => 'Responder']);
    $message = CommsMessage::factory()->create([
        'author_person_id' => CommsPerson::query()->create([
            'user_id' => $user->id,
            'display_name' => 'Marta',
            'relation' => Relation::Collaborator,
        ])->id,
        'text' => '¿El prepago?',
    ]);
    CommsIntentParticipant::query()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'person_id' => CommsPerson::query()->whereBelongsTo($user)->where('relation', Relation::Self)->value('id'),
        'role' => 'responder',
    ]);
    CommsConversationLink::query()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'message_id' => $message->id,
        'operation' => 'open',
        'confidence' => 0.9,
        'uncertain' => false,
    ]);
    AiUsage::query()->create([
        'user_id' => $user->id,
        'task' => 'triage',
        'provider' => 'fake',
        'model' => 'fake',
        'status' => 'ok',
        'input_tokens' => 50,
        'output_tokens' => 0,
        'latency_ms' => 1,
        'cost' => 0,
        'event_ids' => [$message->event_id],
    ]);

    app(AnswerFromSources::class)->run($user, $intent, $message);

    expect(CommsProposal::query()->count())->toBe(0)
        ->and(CommsNotification::query()->where('kind', 'no_draft')->count())->toBe(1);
});

test('search latency is a number on the cutover report', function () {
    $user = phase5User();
    phase5Doc($user, 'notion:prepago', 'notion', 'prepago', 'El flujo de prepago.', null);

    app(ContextEngine::class)->search($user, 'cómo va el prepago');

    $this->artisan('comms:report', ['user' => $user->id, '--date' => now()->toDateString()])
        ->assertSuccessful()
        ->expectsOutputToContain('search p95:');
});

test('jira notion and github comments and changes enter the log', function () {
    $user = phase5User();
    phase5Script([
        phase5Noise(),
        phase5Noise(),
        phase5Noise(),
    ]);
    $ingestor = app(SourceIngestor::class);
    $author = new Party('dev-1', 'dev@example.com', 'Diego');
    $at = phase5At('2026-11-08');

    $ingestor->comment($user, Source::Jira, 'j-1', 'PAY-1', $author, 'Un comentario', $at);
    $ingestor->comment($user, Source::Notion, 'n-1', 'page-1', $author, 'Un comentario en la página', $at);
    $ingestor->comment($user, Source::GitHub, 'gh-1', 'app', $author, 'Un comentario en el pull', $at);
    $changed = $ingestor->changed($user, Source::Jira, 'PAY-1:status', 'status', 'jira:PAY-1', 'PAY-1 pasó a Done', $at);

    expect(CommsMessage::query()->where('source', Source::Jira)->count())->toBe(1)
        ->and(CommsMessage::query()->where('source', Source::Notion)->count())->toBe(1)
        ->and(CommsMessage::query()->where('source', Source::GitHub)->count())->toBe(1)
        ->and($changed->type)->toBe(EventType::SourceChanged);
});

test('playbooks do not call write tools', function () {
    $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Comms/Playbooks')));

    foreach ($paths as $path) {
        if (! $path->isFile() || $path->getExtension() !== 'php') {
            continue;
        }

        expect(file_get_contents($path->getPathname()))->not->toContain('WriteTools');
    }
});

function phase5User(): User
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

function phase5Person(User $user): CommsPerson
{
    $person = CommsPerson::query()->create([
        'user_id' => $user->id,
        'display_name' => 'Marta',
        'relation' => Relation::Collaborator,
        'importance_weight' => Relation::Collaborator->weight(),
    ]);
    CommsIdentity::query()->create([
        'user_id' => $user->id,
        'person_id' => $person->id,
        'source' => Source::Slack,
        'external_id' => 'U-marta',
        'email' => 'marta@example.com',
    ]);

    return $person;
}

function phase5Marta(): Party
{
    return new Party('U-marta', 'marta@example.com', 'Marta');
}

function phase5Me(): Party
{
    return new Party('U-me', 'me@example.com', 'Nicolás');
}

function phase5At(string $moment): CarbonImmutable
{
    return CarbonImmutable::parse(strlen($moment) === 10 ? $moment.'T10:00:00Z' : $moment);
}

/**
 * @param  list<array<string, mixed>>  $bodies
 */
function phase5Script(array $bodies): void
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
function phase5Heard(): array
{
    return ['label' => 'possible_intent', 'urgent' => false, 'sensitive' => false, 'confidence' => 0.9];
}

/**
 * @return array<string, mixed>
 */
function phase5Noise(): array
{
    return ['label' => 'noise', 'urgent' => false, 'sensitive' => false, 'confidence' => 0.9];
}

/**
 * @param  list<array<string, mixed>>  $operations
 * @return array<string, mixed>
 */
function phase5Match(array $operations): array
{
    return ['operations' => $operations, 'confidence' => 0.9];
}

/**
 * @param  list<string>  $requesters
 * @param  list<string>  $responders
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function phase5Open(string $what, string $quote, array $requesters, array $responders, array $extra = []): array
{
    return [...$extra, 'op' => 'open', 'what' => $what, 'quote' => $quote, 'confidence' => 0.9, 'requesters' => $requesters, 'responders' => $responders];
}

/**
 * @param  list<array<string, mixed>>  $points
 * @return array<string, mixed>
 */
function phase5Points(array $points): array
{
    return ['points' => $points];
}

/**
 * @param  list<string>  $keep
 * @return array<string, mixed>
 */
function phase5Keep(array $keep): array
{
    return ['keep' => $keep];
}

/**
 * @param  array<string, mixed>  $extra
 */
function phase5Say(
    User $user,
    string $id,
    string $text,
    Party $author,
    CarbonImmutable $at,
    Source $source = Source::Slack,
    string $channel = 'C1',
    Direction $direction = Direction::Incoming,
    array $extra = [],
): CommsEvent {
    $message = new Message(
        externalId: $id,
        source: $source,
        channel: $channel,
        thread: $channel,
        author: $author,
        recipients: [],
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

function phase5Doc(User $user, string $ref, string $kind, string $title, string $body, ?string $topic): void
{
    $source = $kind === 'code' ? KnowledgeSource::Archive : KnowledgeSource::from($kind);

    KnowledgeDocument::factory()->for($user)->create([
        'source' => $source,
        'ref' => Str::after($ref, $source->value.':'),
        'title' => $title,
        'summary' => $body,
        'terms' => mb_strtolower($title.' '.$body),
        'topic' => $topic,
    ]);
}
