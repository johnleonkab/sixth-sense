<?php

use App\Actions\Actions;
use App\Actions\ActionStatus;
use App\Alerts\AlertFollowUp;
use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\InboxCandidate;
use App\Alerts\Intents\AutonomyLevel;
use App\Alerts\Intents\FakeIntentClassifier;
use App\Alerts\Intents\IntentAssigner;
use App\Alerts\Intents\IntentClassifier;
use App\Alerts\Intents\IntentDecision;
use App\Alerts\Intents\SlackAction;
use App\Alerts\Intents\SlackAutonomy;
use App\Alerts\Replies\FakeReplyPlanner;
use App\Alerts\Replies\ReplyPlanner;
use App\Alerts\Triage;
use App\Analytics\ProductAnalytics;
use App\Analytics\RecordingProductAnalytics;
use App\Connectors\Chat\ChatConversation;
use App\Connectors\Chat\ChatMessage;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\ChatView;
use App\Connectors\Chat\FakeChats;
use App\Connectors\Docs\Documents;
use App\Connectors\Mail\MailAddress;
use App\Jobs\PlanAlertReply;
use App\Models\InboxItem;
use App\Models\SlackTriageDecision;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('a category stays at draft while the sample is under the bar', function () {
    $user = User::factory()->create();
    autonomyDecisions($user, 'redirect', 30, 20);

    $this->actingAs($user)->from(route('settings.alerts.index'))
        ->post(route('settings.slack-autonomy.promote'), ['action' => 'redirect'])
        ->assertRedirect(route('settings.alerts.index'))
        ->assertSessionHasErrors('slack_autonomy');

    expect($user->slackAutonomyLevels()->count())->toBe(0);

    $tool = $this->actingAs($user)->postJson(route('voice.tools.run', 'propose_slack_autonomy'), [
        'arguments' => ['action' => 'redirect'],
        'timezone' => 'Europe/Madrid',
    ]);

    $tool->assertOk();
    expect($tool->json('output.error'))->toContain('30');
});

test('a category rises to act only after yes and the sample clears the bar', function () {
    $user = User::factory()->create();
    $recording = new RecordingProductAnalytics;
    app()->instance(ProductAnalytics::class, $recording);
    autonomyDecisions($user, 'redirect', 30, 21);

    $this->actingAs($user)->post(route('settings.slack-autonomy.promote'), ['action' => 'redirect'])->assertRedirect();

    $pending = $user->pendingActions()->first();

    expect($pending)->not->toBeNull()
        ->and($pending->type)->toBe('slack.autonomy');

    $this->actingAs($user)->post(route('settings.slack-autonomy.confirm', $pending))->assertRedirect();

    expect($user->slackAutonomyLevels()->first()->level)->toBe(AutonomyLevel::Act)
        ->and(collect($recording->events)->firstWhere('event', 'slack_autonomy_promoted')['properties'])->toMatchArray([
            'action' => 'redirect',
            'level' => 'act',
        ]);
});

test('by voice, each level says what it means, and raising shows the bar that waits for the yes', function () {
    $user = User::factory()->create();
    autonomyDecisions($user, 'redirect', 30, 21);
    $user->slackAutonomyLevels()->create(['action' => SlackAction::Reply, 'level' => AutonomyLevel::Act]);

    $raise = $this->actingAs($user)->postJson(route('voice.tools.run', 'propose_slack_autonomy'), ['arguments' => ['action' => 'redirect'], 'timezone' => 'Europe/Madrid'])
        ->assertJsonPath('canvas.view', 'openui.view');

    expect($raise->json('canvas.props.ui'))
        ->toContain('Hacer: Lo envío yo, con unos segundos para deshacer.')
        ->toContain('Button("Bajar a «Proponer»", @Do("autonomy-lower:reply:draft"), "tertiary")')
        ->toContain('Proposal('.$raise->json('output.pending_action_id').', "subir", "Nivel subido")')
        ->toBeValidOpenUi();
});

test('a rise that slips under the bar before yes does not land', function () {
    $user = User::factory()->create();
    autonomyDecisions($user, 'redirect', 30, 21);
    $pending = app(SlackAutonomy::class)->offer($user, SlackAction::Redirect);
    $user->slackTriageDecisions()->update(['confirmed_at' => null]);

    $this->actingAs($user)->post(route('settings.slack-autonomy.confirm', $pending))->assertRedirect();

    expect($user->fresh()->slackAutonomyLevels()->count())->toBe(0)
        ->and($pending->fresh()->status)->toBe(ActionStatus::Failed);
});

test('lowering a category applies at once', function () {
    $user = User::factory()->create();
    $recording = new RecordingProductAnalytics;
    app()->instance(ProductAnalytics::class, $recording);
    $user->slackAutonomyLevels()->create(['action' => SlackAction::Reply, 'level' => AutonomyLevel::Act]);

    $this->actingAs($user)->post(route('settings.slack-autonomy.lower'), [
        'action' => 'reply',
        'level' => 'draft',
    ])->assertRedirect();

    expect($user->slackAutonomyLevels()->first()->level)->toBe(AutonomyLevel::Draft)
        ->and(collect($recording->events)->pluck('event'))->not->toContain('slack_autonomy_promoted');
});

test('another person cannot confirm the rise', function () {
    $user = User::factory()->create();
    autonomyDecisions($user, 'redirect', 30, 21);
    $pending = app(SlackAutonomy::class)->offer($user, SlackAction::Redirect);

    $this->actingAs(User::factory()->create())
        ->post(route('settings.slack-autonomy.confirm', $pending))
        ->assertNotFound();

    expect($user->slackAutonomyLevels()->count())->toBe(0);
});

test('a guest cannot open the ladder', function () {
    $this->postJson(route('voice.tools.run', 'show_slack_autonomy'), ['arguments' => []])
        ->assertUnauthorized();

    $this->post(route('settings.slack-autonomy.lower'), ['action' => 'reply', 'level' => 'watch'])
        ->assertRedirect(route('login'));
});

test('watch explains the message and does not draft it', function () {
    Queue::fake();
    $user = User::factory()->create();
    $user->slackAutonomyLevels()->create(['action' => SlackAction::Reply, 'level' => AutonomyLevel::Watch]);
    $classifier = app(IntentClassifier::class);
    expect($classifier)->toBeInstanceOf(FakeIntentClassifier::class);
    $classifier->next = new IntentDecision(true, 'Te lo marco porque te preguntan.', 80, null, 'El pedido', SlackAction::Reply);

    $item = app(IntentAssigner::class)->assign($user, new InboxCandidate(
        source: AlertSource::Slack,
        externalId: 'msg-watch',
        account: 'jhon@cm.com',
        from: new MailAddress('Julián Soto', 'julian@cm.com'),
        to: [],
        cc: [],
        subject: 'Canal #producto',
        excerpt: '¿cómo va?',
        receivedAt: CarbonImmutable::parse('2026-10-28 10:00', 'Europe/Madrid'),
        labels: ['channel'],
        threadKey: 'hilo-watch',
    ));

    expect($item)->not->toBeNull()
        ->and($item->planning)->toBeFalse()
        ->and($item->proposal['summary'])->toBe('Te lo marco porque te preguntan.')
        ->and($item->proposal['reply'])->toBeNull();

    Queue::assertNotPushed(PlanAlertReply::class);
});

test('act sends a sufficient draft and keeps the undo window', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create(['name' => 'John León']);
    $recording = new RecordingProductAnalytics;
    app()->instance(ProductAnalytics::class, $recording);
    app()->instance(ReplyPlanner::class, new FakeReplyPlanner);
    $user->slackAutonomyLevels()->create(['action' => SlackAction::Reply, 'level' => AutonomyLevel::Act]);
    $item = InboxItem::factory()->for($user)->create([
        'source' => AlertSource::Slack,
        'external_id' => 'demo:C9:2000.000100',
        'actor_name' => 'Julián Soto',
        'title' => 'Canal #producto',
        'body' => '¿cómo va el despliegue?',
        'status' => AlertStatus::Pending,
        'triage' => Triage::Important,
    ]);
    $decision = SlackTriageDecision::query()->create([
        'user_id' => $user->id,
        'inbox_item_id' => $item->id,
        'external_id' => $item->external_id,
        'action' => 'reply',
        'place' => 'channel',
        'owner' => 'self',
        'reason' => 'Te pregunta por ello.',
        'thread_key' => 'demo:C9:2000.000100',
        'channel' => '#producto',
        'candidate' => ['external_id' => $item->external_id, 'excerpt' => '¿cómo va el despliegue?'],
    ]);
    $chats = mock(Chats::class);
    app()->instance(Chats::class, $chats);
    $at = CarbonImmutable::parse('2026-10-28 10:00');
    $chats->shouldReceive('view')->andReturn(new ChatView(
        new ChatConversation('demo:C9', 'demo', 'channel', 'producto'),
        [
            new ChatMessage('demo:C9:2000.000050', 'demo:C9', '#producto', 'Marta Ruiz', 'U9', 'El despliegue va por el paso de pagos y falta el ok.', $at->subHour()),
            new ChatMessage('demo:C9:2000.000100', 'demo:C9', '#producto', 'Julián Soto', 'U5', '¿cómo va el despliegue?', $at),
        ],
        'demo:C9:2000.000100',
    ));
    $chats->shouldReceive('search')->never();
    $chats->shouldReceive('canSend')->once()->andReturn(true);
    $chats->shouldReceive('send')->once()->andReturn('sent-1');
    $documents = mock(Documents::class);
    app()->instance(Documents::class, $documents);
    $documents->shouldReceive('search')->never();
    $documents->shouldReceive('find')->never();

    app(AlertFollowUp::class)->plan($item);

    $confirmed = collect($recording->events)->firstWhere('event', 'slack_draft_confirmed');

    expect($decision->fresh()->autonomy)->toBe('auto')
        ->and($decision->fresh()->edited)->toBe('none')
        ->and($decision->fresh()->confirmed_at)->not->toBeNull()
        ->and($confirmed['properties'])->toMatchArray(['action' => 'reply', 'edited' => 'none', 'autonomy' => 'auto'])
        ->and($confirmed['properties'])->not->toHaveKey('text');
});

test('confirming a rewritten draft records the distance and not the text', function () {
    $user = User::factory()->create();
    $recording = new RecordingProductAnalytics;
    app()->instance(ProductAnalytics::class, $recording);
    app()->instance(Chats::class, new FakeChats);
    $decision = autonomyDecisions($user, 'reply', 1, 0)->first();
    $decision->update(['draft_text' => 'Hola, lo miro hoy y te digo algo.']);
    $pending = app(Actions::class)->propose($user, 'chat.send', 'Enviar', [
        'message' => ['to' => 'demo:C9', 'to_label' => '#producto', 'text' => 'Un texto completamente distinto sobre otra cosa.', 'mentions' => [], 'thread_ref' => null],
        'slack_decision_id' => $decision->id,
        'proposed_text' => $decision->draft_text,
        'autonomy' => 'propose',
    ]);

    app(Actions::class)->confirm($pending);

    expect($decision->fresh()->edited)->toBe('rewritten')
        ->and($decision->fresh()->autonomy)->toBe('propose')
        ->and(collect($recording->events)->firstWhere('event', 'slack_draft_confirmed')['properties'])->not->toHaveKey('text');
});

test('the board reads the five numbers from what is stored here', function () {
    $user = User::factory()->create();
    $missed = autonomyDecisions($user, 'ignore', 1, 0)->first();
    $missed->update(['missed_at' => now()]);
    $reply = autonomyDecisions($user, 'reply', 1, 1)->first();
    $reply->update(['confirmed_at' => now()->addHours(4), 'edited' => 'none']);

    $board = collect(app(SlackAutonomy::class)->present($user)['board'])->pluck('value', 'label');

    expect($board['Se me escapó'])->toBe('50 %')
        ->and($board['Redirecciones aceptadas'])->toBe('Aún no hay muestra')
        ->and($board['Horas hasta confirmar un «responde tú»'])->toBe('4 h');
});

/**
 * @return Collection<int, SlackTriageDecision>
 */
function autonomyDecisions(User $user, string $action, int $total, int $confirmed): Collection
{
    $rows = collect();

    for ($index = 0; $index < $total; $index++) {
        $rows->push($user->slackTriageDecisions()->create([
            'external_id' => $action.'-'.$index.'-'.$user->id,
            'action' => $action,
            'place' => 'channel',
            'owner' => 'other',
            'reason' => 'Motivo',
            'has_target' => $action === 'redirect',
            'channel' => '#producto',
            'candidate' => [],
            'confirmed_at' => $index < $confirmed ? now() : null,
            'edited' => $index < $confirmed ? 'none' : null,
            'autonomy' => $index < $confirmed ? 'propose' : null,
        ]));
    }

    return $rows;
}
