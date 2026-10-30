<?php

use App\Analytics\ProductAnalytics;
use App\Analytics\RecordingProductAnalytics;
use App\Models\SlackTriageDecision;
use App\Models\User;

test('messages left aside since yesterday are shown and each open is counted', function () {
    $this->travelTo('2026-10-28 18:00:00');
    $user = User::factory()->create();
    $recording = new RecordingProductAnalytics;
    app()->instance(ProductAnalytics::class, $recording);
    leftAside($user, 'ignore', 'el anuncio de ayer', '2026-10-27 10:00:00');
    leftAside($user, 'notice', 'para que lo sepas', '2026-10-28 09:00:00', reason: 'Anuncio del canal, para que lo sepas.');
    leftAside($user, 'ignore', 'presupuesto-secreto-q4', '2026-10-26 10:00:00');
    leftAside($user, 'reply', 'esto sí sonó', '2026-10-28 09:30:00');
    leftAside($user, 'ignore', 'ya lo recuperamos', '2026-10-28 08:00:00', missedAt: '2026-10-28 12:00:00');
    leftAside(User::factory()->create(), 'ignore', 'de otra persona', '2026-10-28 08:00:00');

    $open = fn () => $this->actingAs($user)->postJson(route('voice.tools.run', 'show_slack_digest'), [
        'arguments' => [],
        'timezone' => 'Europe/Madrid',
    ]);

    $digest = $open()
        ->assertOk()
        ->assertJsonPath('canvas.view', 'openui.view')
        ->assertJsonPath('canvas.props.title', '2 dejados pasar desde ayer')
        ->assertJsonPath('canvas.props.items.0.tool', 'show_slack_conversation')
        ->assertJsonPath('output.total', 2);

    // Each one opens its conversation, and its three corrections propose a rule without the assistant.
    $decision = $user->slackTriageDecisions()->where('action', 'notice')->sole();

    expect($digest->json('canvas.props.ui'))
        ->toContain('title: "para que lo sepas"')
        ->toContain('eyebrow: "Julián Soto · ')
        ->toContain('snippet: "Anuncio del canal, para que lo sepas."')
        ->toContain("{label: \"No hace falta\", ref: \"correct:{$decision->id}:not_important\"}")
        ->toContain('title: "el anuncio de ayer"')
        ->toBeValidOpenUi();

    $open();

    expect($recording->events)->toHaveCount(2)
        ->and($recording->events[0]['event'])->toBe('slack_daily_digest_read')
        ->and($recording->events[0]['properties'])->toBe(['deprioritized_count' => 2])
        ->and($recording->events[1]['properties'])->toBe(['deprioritized_count' => 2])
        ->and(json_encode($recording->events))->not->toContain('presupuesto-secreto-q4');
});

test('an empty digest says nothing was left aside and counts zero', function () {
    $user = User::factory()->create();
    $recording = new RecordingProductAnalytics;
    app()->instance(ProductAnalytics::class, $recording);

    $this->actingAs($user)->postJson(route('voice.tools.run', 'show_slack_digest'), [
        'arguments' => [],
        'timezone' => 'Europe/Madrid',
    ])
        ->assertOk()
        ->assertJsonPath('canvas.props.title', 'Nada dejado pasar desde ayer')
        ->assertJsonPath('canvas.props.items', [])
        ->assertJsonPath('output.total', 0);

    expect($recording->events[0]['properties'])->toBe(['deprioritized_count' => 0]);
});

test('returns 401 when the digest is opened without a session', function () {
    $this->postJson(route('voice.tools.run', 'show_slack_digest'), ['arguments' => []])
        ->assertUnauthorized();
});

function leftAside(User $user, string $action, string $excerpt, string $at, ?string $missedAt = null, string $reason = 'Informativo, sin nada que hacer.'): SlackTriageDecision
{
    $decision = $user->slackTriageDecisions()->create([
        'external_id' => $excerpt,
        'action' => $action,
        'place' => 'channel',
        'owner' => 'unknown',
        'reason' => $reason,
        'thread_key' => 'hilo-1',
        'candidate' => [
            'external_id' => 'acct:C1:1',
            'excerpt' => $excerpt,
            'from' => ['name' => 'Julián Soto', 'email' => 'julian@cm.com'],
        ],
    ]);
    $decision->forceFill(['created_at' => $at, 'missed_at' => $missedAt])->save();

    return $decision;
}
