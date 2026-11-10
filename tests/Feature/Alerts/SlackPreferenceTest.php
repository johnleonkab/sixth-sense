<?php

use App\Alerts\AlertSource;
use App\Alerts\InboxCandidate;
use App\Alerts\Intents\FakeIntentClassifier;
use App\Alerts\Intents\IntentAssigner;
use App\Alerts\Intents\IntentClassifier;
use App\Alerts\Intents\IntentDecision;
use App\Alerts\Intents\SlackAction;
use App\Analytics\ProductAnalytics;
use App\Analytics\RecordingProductAnalytics;
use App\Comms\Context\Audience;
use App\Comms\Context\ContextEngine;
use App\Comms\Context\ContextRenderer;
use App\Comms\Context\ContextRequest;
use App\Comms\Context\ContextWriter;
use App\Comms\Context\FactOrigin;
use App\Connections\Provider;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Slack\SlackAttention;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

test('a tap proposes a rule and the next similar message follows it only after yes', function () {
    [$user, $recording] = preferencePerson();
    preferenceDecide($user, SlackAction::Reply, 'Hay que contestar en el canal.');

    $item = app(IntentAssigner::class)->assign($user, preferenceMessage('msg-1', 'hilo-1'));

    $tap = $this->actingAs($user)->postJson(route('voice.tools.run', 'correct_slack_triage'), [
        'arguments' => ['alert_id' => $item->id, 'correction' => 'not_important'],
        'timezone' => 'Europe/Madrid',
    ]);

    $tap->assertOk();
    $rule = 'En #producto, mensajes de este tipo: no hace falta avisar.';

    expect(slackPolicy($user))->not->toContain($rule)
        ->and($user->slackPreferenceRules()->first()->status)->toBe('proposed')
        ->and($tap->json('output.rule'))->toBe($rule);

    // On screen, the rule and the bar that waits for the "sí".
    expect($tap->json('canvas.view'))->toBe('openui.view')
        ->and($tap->json('canvas.props.ui'))->toContain('Proposal('.$tap->json('output.pending_action_id').', "guardar", "Regla guardada")')->toBeValidOpenUi();

    $names = collect($recording->events)->pluck('event');

    expect($names)->toContain('slack_triage_feedback')
        ->and($names)->toContain('slack_preference_rule_proposed')
        ->and(collect($recording->events)->firstWhere('event', 'slack_triage_feedback')['properties'])->toMatchArray([
            'action' => 'reply',
            'correction' => 'not_important',
        ])
        ->and(collect($recording->events)->firstWhere('event', 'slack_triage_feedback')['properties'])->not->toHaveKey('reason');

    preferenceDecide($user, SlackAction::Reply, 'Hay que contestar en el canal.');
    $before = app(IntentAssigner::class)->assign($user, preferenceMessage('msg-2', 'hilo-2'));

    expect($before)->not->toBeNull()
        ->and($user->slackTriageDecisions()->where('external_id', 'msg-2')->first()->action)->toBe('reply');

    $this->actingAs($user)->postJson(route('actions.confirm', $tap->json('output.pending_action_id')))->assertOk();

    expect($user->slackPreferenceRules()->first()->status)->toBe('accepted')
        ->and(slackPolicy($user))->toContain($rule)
        ->and(collect($recording->events)->pluck('event'))->toContain('slack_preference_rule_accepted');

    preferenceDecide($user, SlackAction::Reply, 'Hay que contestar en el canal.');
    $after = app(IntentAssigner::class)->assign($user, preferenceMessage('msg-3', 'hilo-3'));

    expect($after)->toBeNull()
        ->and($user->slackTriageDecisions()->where('external_id', 'msg-3')->first()->action)->toBe('ignore');
});

test('a profile older than a quarter keeps half its weight and a second week does not halve it again', function () {
    [$user] = preferencePerson(false);
    $this->travelTo(now()->subMonths(4));
    preferenceProfile($user, 'Contestó mucho.', ['threads' => 10, 'replied' => 10, 'missed' => 0, 'other' => 0, 'noise' => 0]);
    $this->travelBack();
    Http::preventStrayRequests();
    Http::fake([
        'slack.com/*' => Http::response(['ok' => true, 'messages' => ['matches' => []]]),
    ]);

    app(SlackAttention::class)->learn($user);

    $profile = app(ContextEngine::class)->slackProfile($user);

    expect($profile['text'])->toContain('Esto venía del trimestre pasado.')
        ->and($profile['signals']['aged']['replied'])->toBe(5)
        ->and($profile['text'])->toContain('¿A quién respondes siempre?');

    app(SlackAttention::class)->learn($user);

    expect(app(ContextEngine::class)->slackProfile($user)['signals']['aged']['replied'])->toBe(5);
});

test('a cold start asks three questions and an answer is already a rule', function () {
    [$user, $recording] = preferencePerson(false);
    Http::preventStrayRequests();
    Http::fake([
        'slack.com/*' => Http::response(['ok' => true, 'messages' => ['matches' => []]]),
    ]);

    app(SlackAttention::class)->learn($user);

    $body = app(ContextEngine::class)->slackProfile($user)['text'];

    expect($body)->toContain('¿A quién respondes siempre?')
        ->and($body)->toContain('¿Qué proyectos son de este trimestre?')
        ->and($body)->toContain('¿Qué canales son ruido?');

    $this->actingAs($user)->postJson(route('voice.tools.run', 'answer_slack_attention'), [
        'arguments' => ['people' => 'Julián Soto'],
        'timezone' => 'Europe/Madrid',
    ])->assertOk();

    expect($user->slackPreferenceRules()->first()->status)->toBe('accepted')
        ->and(slackPolicy($user))->toContain('Responde siempre a Julián Soto.')
        ->and(collect($recording->events)->pluck('event'))->toContain('slack_preference_rule_accepted')
        ->and(collect($recording->events)->pluck('event'))->not->toContain('slack_preference_rule_proposed');

    preferenceDecide($user, SlackAction::Ignore, 'Informativo, sin nada que hacer.');
    $item = app(IntentAssigner::class)->assign($user, preferenceMessage('msg-dm', 'hilo-dm', 'dm', 'Mensaje directo', '¿lo miramos?'));

    expect($item)->not->toBeNull()
        ->and($user->slackTriageDecisions()->where('external_id', 'msg-dm')->first()->action)->toBe('reply');
});

test('a guest cannot correct a slack triage', function () {
    $this->postJson(route('voice.tools.run', 'correct_slack_triage'), [
        'arguments' => ['alert_id' => 1, 'correction' => 'not_important'],
    ])->assertUnauthorized();
});

test('another persons decision is not correctable', function () {
    [$owner] = preferencePerson(false);
    preferenceDecide($owner, SlackAction::Reply, 'Hay que contestar en el canal.');
    app(IntentAssigner::class)->assign($owner, preferenceMessage('msg-ajena', 'hilo-ajeno'));
    $decision = $owner->slackTriageDecisions()->first();
    $other = User::factory()->create();

    $this->actingAs($other)->postJson(route('voice.tools.run', 'correct_slack_triage'), [
        'arguments' => ['decision_id' => $decision->id, 'correction' => 'not_important'],
        'timezone' => 'Europe/Madrid',
    ])->assertNotFound();

    expect($owner->slackPreferenceRules()->count())->toBe(0);
});

/**
 * @return array{0: User, 1: RecordingProductAnalytics}
 */
function preferencePerson(bool $withProfile = true): array
{
    $user = User::factory()->create(['name' => 'John León']);
    ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Slack,
        'external_account_id' => 'T1:U1',
        'email' => 'jhon@cm.com',
        'access_token' => 'xoxp-1',
        'expires_at' => null,
        'scopes' => Provider::Slack->scopes(),
        'metadata' => ['team_id' => 'T1', 'team_name' => 'CoverManager', 'user_id' => 'U1'],
    ]);

    if ($withProfile) {
        preferenceProfile($user, 'Canales que parecen suyos: #producto.', [
            'threads' => 3,
            'replied' => 2,
            'missed' => 0,
            'other' => 1,
            'noise' => 0,
            'people' => [],
            'channels' => [],
            'owned_channels' => ['#producto'],
        ]);
    }

    $recording = new RecordingProductAnalytics;
    app()->instance(ProductAnalytics::class, $recording);

    return [$user, $recording];
}

function preferenceDecide(User $user, SlackAction $action, string $reason): void
{
    $classifier = app(IntentClassifier::class);
    expect($classifier)->toBeInstanceOf(FakeIntentClassifier::class);

    $classifier->next = new IntentDecision(
        important: $action->shown(),
        reason: $reason,
        relevance: $action->shown() ? 80 : 10,
        intentId: null,
        goal: 'El pedido',
        action: $action,
    );
}

function preferenceMessage(string $ref, string $thread, string $place = 'channel', string $subject = 'Canal #producto', string $text = '¿cómo va el despliegue?'): InboxCandidate
{
    return new InboxCandidate(
        source: AlertSource::Slack,
        externalId: $ref,
        account: 'jhon@cm.com',
        from: new MailAddress('Julián Soto', 'julian@cm.com'),
        to: [],
        cc: [],
        subject: $subject,
        excerpt: $text,
        receivedAt: CarbonImmutable::parse('2026-10-28 10:00', 'Europe/Madrid'),
        labels: [$place],
        threadKey: $thread,
    );
}

/**
 * @param  array<string, mixed>  $signals
 */
function preferenceProfile(User $user, string $text, array $signals): void
{
    app(ContextWriter::class)->record($user, FactOrigin::Observed, [[
        'op' => 'set',
        'kind' => 'channel',
        'subject' => 'slack',
        'attribute' => 'profile',
        'value' => ['text' => $text, 'signals' => $signals],
        'audiences' => [Audience::Triage->value],
    ]]);
}

/**
 * What the Slack triage reads about how the person answers.
 */
function slackPolicy(User $user): string
{
    return app(ContextRenderer::class)->block($user, new ContextRequest(Audience::Triage, ['slack']));
}
