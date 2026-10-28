<?php

use App\Analytics\PostHogProductAnalytics;
use App\Analytics\RecordingProductAnalytics;
use App\Analytics\SlackTriageEvent;
use App\Models\User;
use App\Services\PostHogService;

use function Pest\Laravel\mock;

test('a triage event drops message text and anything outside the whitelist', function () {
    $analytics = new RecordingProductAnalytics;
    $user = User::factory()->create();

    $analytics->capture($user, SlackTriageEvent::TriageDecided, [
        'action' => 'reply',
        'place' => 'dm',
        'owner' => 'self',
        'uncertain' => false,
        'policy_present' => true,
        'text' => 'Laura, mira el presupuesto antes del viernes',
        'reason' => 'Laura te pregunta por el budget',
        'name' => 'Laura',
        'action_note' => 'responde tú',
    ]);

    expect($analytics->events)->toBe([[
        'event' => 'slack_triage_decided',
        'distinct_id' => (string) $user->id,
        'properties' => [
            'action' => 'reply',
            'place' => 'dm',
            'owner' => 'self',
            'uncertain' => false,
            'policy_present' => true,
        ],
    ]]);
});

test('the posthog adapter forwards only the whitelist', function () {
    $user = User::factory()->create();

    mock(PostHogService::class)
        ->shouldReceive('capture')
        ->once()
        ->with((string) $user->id, 'slack_attention_profile_built', [
            'threads' => 4,
            'replied' => 2,
            'missed' => 1,
        ]);

    app(PostHogProductAnalytics::class)->capture($user, SlackTriageEvent::AttentionProfileBuilt, [
        'threads' => 4,
        'replied' => 2,
        'missed' => 1,
        'body' => 'Contesta a Laura sobre el contrato',
    ]);
});
