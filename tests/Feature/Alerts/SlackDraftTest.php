<?php

use App\Alerts\AlertFollowUp;
use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\Replies\FakeReplyPlanner;
use App\Alerts\Replies\ReplyPlanner;
use App\Alerts\Triage;
use App\Analytics\ProductAnalytics;
use App\Analytics\RecordingProductAnalytics;
use App\Connectors\Chat\ChatConversation;
use App\Connectors\Chat\ChatMessage;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\ChatView;
use App\Connectors\Docs\Document;
use App\Connectors\Docs\Documents;
use App\Connectors\Docs\DocumentSearchResult;
use App\Connectors\Docs\DocumentSummary;
use App\Models\InboxItem;
use App\Models\SenseAgent;
use App\Models\SlackTriageDecision;
use App\Models\User;
use App\Models\WorkContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

test('a reply draft cites the thread and stops looking', function () {
    Http::preventStrayRequests();
    [$user, $item, $recording] = draftedAlert('¿cómo va el despliegue?');
    $chats = mock(Chats::class);
    app()->instance(Chats::class, $chats);
    $chats->shouldReceive('view')->andReturn(threadView('¿cómo va el despliegue?', 'El despliegue va por el paso de pagos y falta el ok.'));
    $chats->shouldReceive('search')->never();
    $documents = mock(Documents::class);
    app()->instance(Documents::class, $documents);
    $documents->shouldReceive('search')->never();
    $documents->shouldReceive('find')->never();

    app(AlertFollowUp::class)->plan($item);

    $reply = $item->refresh()->proposal['reply']['text'];

    expect($reply)->toContain('Según el hilo')
        ->and($reply)->toContain('paso de pagos')
        ->and($recording->events[0]['event'])->toBe('slack_reply_planned')
        ->and($recording->events[0]['properties'])->toMatchArray([
            'action' => 'reply',
            'sufficient' => true,
            'agent_asked' => false,
            'conflict' => false,
        ])
        ->and($recording->events[0]['properties'])->not->toHaveKey('reason');
});

test('a reply draft cites the work archive when the thread says nothing', function () {
    Http::preventStrayRequests();
    [$user, $item] = draftedAlert('¿cómo va el despliegue?');
    WorkContext::factory()->for($user)->ready()->create([
        'summary' => 'El despliegue sale el jueves según el archivo de trabajo.',
        'generated_at' => now(),
    ]);
    $chats = mock(Chats::class);
    app()->instance(Chats::class, $chats);
    $chats->shouldReceive('view')->andReturn(threadView('¿cómo va el despliegue?'));
    $chats->shouldReceive('search')->never();
    $documents = mock(Documents::class);
    app()->instance(Documents::class, $documents);
    $documents->shouldReceive('search')->never();

    app(AlertFollowUp::class)->plan($item);

    expect($item->refresh()->proposal['reply']['text'])->toContain('Según el archivo')
        ->and($item->proposal['reply']['text'])->toContain('jueves');
});

test('a reply draft says what is missing when nothing answers the ask', function () {
    Http::preventStrayRequests();
    [, $item, $recording] = draftedAlert('¿cuál es el presupuesto?');
    $chats = mock(Chats::class);
    app()->instance(Chats::class, $chats);
    $chats->shouldReceive('view')->andReturn(threadView('¿cuál es el presupuesto?'));
    $chats->shouldReceive('search')->andReturn([]);
    $documents = mock(Documents::class);
    app()->instance(Documents::class, $documents);
    $documents->shouldReceive('search')->andReturn(new DocumentSearchResult([], []));

    app(AlertFollowUp::class)->plan($item);

    expect($item->refresh()->proposal['reply']['text'])->toContain('Para responder hay que confirmar')
        ->and($item->proposal['question'])->toContain('presupuesto')
        ->and($recording->events[0]['properties']['sufficient'])->toBeFalse();
});

test('a matching agent briefing is used once', function () {
    Http::preventStrayRequests();
    [$user, $item, $recording] = draftedAlert('¿cómo va el despliegue?');
    SenseAgent::factory()->for($user)->create([
        'name' => 'Despliegue',
        'description' => 'Úsalo cuando hablen del despliegue',
        'instructions' => 'Sale el jueves por la noche.',
    ]);
    SenseAgent::factory()->for($user)->create([
        'name' => 'Contrato',
        'description' => 'Úsalo cuando hablen del contrato',
        'instructions' => 'clausula-secreta-del-contrato',
    ]);
    $chats = mock(Chats::class);
    app()->instance(Chats::class, $chats);
    $chats->shouldReceive('view')->andReturn(threadView('¿cómo va el despliegue?', 'El despliegue va por el paso de pagos y falta el ok.'));
    $chats->shouldReceive('search')->never();
    $documents = mock(Documents::class);
    app()->instance(Documents::class, $documents);
    $documents->shouldReceive('search')->never();

    app(AlertFollowUp::class)->plan($item);

    $reply = $item->refresh()->proposal['reply']['text'];

    expect($reply)->toContain('El agente Despliegue')
        ->and($reply)->toContain('jueves por la noche')
        ->and($reply)->not->toContain('clausula-secreta-del-contrato')
        ->and($recording->events[0]['properties']['agent_asked'])->toBeTrue();
});

test('a thread decision that contradicts a document is written into the draft', function () {
    Http::preventStrayRequests();
    [, $item, $recording] = draftedAlert('¿cuál es el plazo del despliegue?');
    $chats = mock(Chats::class);
    app()->instance(Chats::class, $chats);
    $chats->shouldReceive('view')->andReturn(threadView('¿cuál es el plazo del despliegue?', 'El plazo queda el viernes para el despliegue.'));
    $chats->shouldReceive('search')->never();
    $documents = mock(Documents::class);
    app()->instance(Documents::class, $documents);
    $documents->shouldReceive('search')->once()->andReturn(new DocumentSearchResult([
        new DocumentSummary('demo.doc', 'demo@sixth-sense.test', 'Plan de despliegue', null, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-02-01')),
    ], []));
    $documents->shouldReceive('find')->once()->andReturn(new Document(
        'demo.doc',
        'demo@sixth-sense.test',
        'Plan de despliegue',
        '1',
        [],
        [['text' => 'El plazo es el lunes.', 'start' => 0]],
    ));

    app(AlertFollowUp::class)->plan($item);

    $reply = $item->refresh()->proposal['reply']['text'];

    expect($reply)->toContain('no coinciden')
        ->and($reply)->toContain('viernes')
        ->and($reply)->toContain('lunes')
        ->and($recording->events[0]['properties']['conflict'])->toBeTrue();
});

test('a private reply is not copied into a channel draft', function () {
    Http::preventStrayRequests();
    [$user, $item] = draftedAlert('¿cuál es el presupuesto?', 'channel');
    $chats = mock(Chats::class);
    app()->instance(Chats::class, $chats);
    $chats->shouldReceive('view')->andReturn(threadView('¿cuál es el presupuesto?'));
    $chats->shouldReceive('search')->once()->andReturn([
        new ChatMessage('demo:D1:1', 'demo:D1', 'Marta Ruiz', $user->name, 'U1', 'el presupuesto secreto es cuarenta mil', CarbonImmutable::parse('2026-10-27 10:00'), isMine: true),
    ]);
    $documents = mock(Documents::class);
    app()->instance(Documents::class, $documents);
    $documents->shouldReceive('search')->andReturn(new DocumentSearchResult([], []));

    app(AlertFollowUp::class)->plan($item);

    expect($item->refresh()->proposal['reply']['text'])->not->toContain('cuarenta')
        ->and($item->proposal['reply']['text'])->toContain('confirmar');
});

/**
 * @return array{0: User, 1: InboxItem, 2: RecordingProductAnalytics}
 */
function draftedAlert(string $ask, string $place = 'dm'): array
{
    $user = User::factory()->create(['name' => 'John León']);
    $item = InboxItem::factory()->for($user)->create([
        'source' => AlertSource::Slack,
        'external_id' => 'demo:C9:2000.000100',
        'actor_name' => 'Julián Soto',
        'title' => 'Mensaje directo',
        'body' => $ask,
        'status' => AlertStatus::Pending,
        'triage' => Triage::Important,
    ]);
    SlackTriageDecision::query()->create([
        'user_id' => $user->id,
        'inbox_item_id' => $item->id,
        'external_id' => $item->external_id,
        'action' => 'reply',
        'place' => $place,
        'owner' => 'self',
        'reason' => 'Te pregunta por ello.',
        'candidate' => ['external_id' => $item->external_id, 'excerpt' => $ask],
    ]);
    $recording = new RecordingProductAnalytics;
    app()->instance(ProductAnalytics::class, $recording);
    app()->instance(ReplyPlanner::class, new FakeReplyPlanner);

    return [$user, $item, $recording];
}

function threadView(string $ask, ?string $earlier = null): ChatView
{
    $at = CarbonImmutable::parse('2026-10-28 10:00');
    $messages = [];

    if ($earlier !== null) {
        $messages[] = new ChatMessage('demo:C9:2000.000050', 'demo:C9', '#producto', 'Marta Ruiz', 'U9', $earlier, $at->subHour());
    }

    $messages[] = new ChatMessage('demo:C9:2000.000100', 'demo:C9', '#producto', 'Julián Soto', 'U5', $ask, $at);

    return new ChatView(new ChatConversation('demo:C9', 'demo', 'channel', 'producto'), $messages, 'demo:C9:2000.000100');
}
