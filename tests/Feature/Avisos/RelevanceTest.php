<?php

use App\Alerts\AlertSource;
use App\Avisos\Candidate;
use App\Avisos\Decision;
use App\Avisos\DoubtResolver;
use App\Avisos\Engine;
use App\Avisos\Relevance;
use App\Connections\Provider;
use App\Connectors\Wiki\FakeWiki;
use App\Connectors\Wiki\Wiki;
use App\Events\InboxItemCreated;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

function aviso(AlertSource $source, array $overrides = []): Candidate
{
    return new Candidate(
        source: $source,
        externalId: $overrides['external_id'] ?? 'ext-1',
        actorName: $overrides['actor'] ?? 'Marta Ruiz',
        actorEmail: $overrides['email'] ?? 'marta@example.com',
        title: $overrides['title'] ?? 'Prepago',
        body: $overrides['body'] ?? 'Hola',
        url: 'https://example.test/1',
        receivedAt: CarbonImmutable::parse('2026-11-15 09:00:00'),
        directMessage: $overrides['dm'] ?? false,
        mention: $overrides['mention'] ?? false,
        importantSender: $overrides['important'] ?? false,
        replyToOwnThread: $overrides['reply'] ?? false,
        bulk: $overrides['bulk'] ?? false,
        fromSelf: $overrides['self'] ?? false,
        ownedPage: $overrides['owned'] ?? false,
        assignedTask: $overrides['task'] ?? false,
        inInbox: $overrides['inbox'] ?? true,
    );
}

test('slack mentions and direct messages are avisos and other slack is not', function (array $flags, bool $relevant) {
    $decision = app(Relevance::class)->decide(aviso(AlertSource::Slack, $flags));

    expect($decision->relevant)->toBe($relevant);

    if ($relevant) {
        expect($decision->why)->not->toBeEmpty();
    }
})->with([
    'direct message' => [['dm' => true], true],
    'mention' => [['mention' => true, 'title' => '#producto'], true],
    'channel noise' => [[], false],
    'own message' => [['dm' => true, 'self' => true], false],
]);

test('gmail keeps important senders, replies to threads the person started, and explicit requests', function (array $flags, bool $relevant) {
    $decision = app(Relevance::class)->decide(aviso(AlertSource::Gmail, $flags));

    expect($decision->relevant)->toBe($relevant);

    if ($relevant) {
        expect($decision->why)->not->toBeEmpty();
    }
})->with([
    'important sender' => [['important' => true, 'body' => 'Os mando el acta.'], true],
    'reply to own thread' => [['reply' => true, 'body' => 'Recibido, gracias.'], true],
    'explicit request' => [['title' => 'Contrato', 'body' => 'Necesito la firma antes del viernes.'], true],
    'newsletter' => [['bulk' => true, 'body' => 'Necesito que confirmes tu suscripción.'], false],
    'not in the inbox' => [['body' => '¿Puedes mirarlo?', 'inbox' => false], false],
    'nothing asked' => [['title' => 'Gracias', 'body' => 'Un placer verte ayer.'], false],
    'own mail' => [['self' => true, 'body' => 'Necesito apuntarlo.'], false],
]);

test('a gmail ask that only appears in quoted text is doubtful and stays out', function () {
    $candidate = aviso(AlertSource::Gmail, [
        'title' => 'Re: acta',
        'body' => "Gracias por el resumen.\n> ¿Puedes firmarlo hoy?",
    ]);

    expect(app(Relevance::class)->decide($candidate)->doubtful)->toBeTrue()
        ->and(app(Engine::class)->score(User::factory()->create(), $candidate))->toBeNull();
});

test('a doubt resolver can still surface a quoted ask, and every aviso stores why', function () {
    Event::fake([InboxItemCreated::class]);
    $this->app->instance(DoubtResolver::class, new class implements DoubtResolver
    {
        public function resolve(User $user, Candidate $candidate, Decision $decision): Decision
        {
            return Decision::surface('La cita parece una petición.', 60);
        }
    });
    $user = User::factory()->create();

    $item = app(Engine::class)->score($user, aviso(AlertSource::Gmail, [
        'title' => 'Re: acta',
        'body' => "Gracias.\n> ¿Puedes firmarlo?",
    ]));

    expect($item)->not->toBeNull()
        ->and($item->why)->toBe('La cita parece una petición.')
        ->and($item->user_id)->toBe($user->id);

    Event::assertDispatched(InboxItemCreated::class);
});

test('jira mentions and notion comments and assigned tasks are avisos', function (AlertSource $source, array $flags, bool $relevant) {
    $decision = app(Relevance::class)->decide(aviso($source, $flags));

    expect($decision->relevant)->toBe($relevant);

    if ($relevant) {
        expect($decision->why)->not->toBeEmpty();
    }
})->with([
    'jira mention' => [AlertSource::Jira, ['mention' => true, 'title' => 'CPD-1432'], true],
    'jira without a mention' => [AlertSource::Jira, [], false],
    'notion comment on an owned page' => [AlertSource::Notion, ['owned' => true], true],
    'notion assigned task' => [AlertSource::Notion, ['task' => true, 'title' => 'Revisión de Riesgos'], true],
    'notion page with neither' => [AlertSource::Notion, [], false],
]);

test('the same external id is stored once for a user and source', function () {
    Event::fake([InboxItemCreated::class]);
    $user = User::factory()->create();
    $engine = app(Engine::class);
    $candidate = aviso(AlertSource::Slack, ['dm' => true, 'external_id' => 'slack-1']);

    expect($engine->score($user, $candidate))->not->toBeNull()
        ->and($engine->score($user, $candidate))->toBeNull()
        ->and($user->inboxItems()->count())->toBe(1);

    Event::assertDispatchedTimes(InboxItemCreated::class, 1);
});

test('a notion comment on a page the wiki returns becomes an aviso', function () {
    $this->app->instance(Wiki::class, new FakeWiki);
    $user = User::factory()->create(['name' => 'John León']);
    $account = ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Notion,
        'watch_cursor' => CarbonImmutable::yesterday()->toIso8601String(),
    ]);

    $items = app(Engine::class)->check($account, AlertSource::Notion);

    expect($items)->toHaveCount(1)
        ->and($items[0]->source)->toBe(AlertSource::Notion)
        ->and($items[0]->actor_name)->toBe('Marta Ruiz')
        ->and($items[0]->why)->toContain('página tuya')
        ->and($items[0]->user_id)->toBe($user->id);
});
