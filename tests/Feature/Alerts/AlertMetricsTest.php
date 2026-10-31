<?php

use App\Alerts\Outcomes\AlertMetrics;
use App\Models\ProposalOutcome;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // Wednesday 14 October: this week started on Monday the 12th.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'Europe/Madrid'));
    $this->user = User::factory()->create();
});

function ended(string $outcome, array $values = [], string $when = '2026-10-14 10:00'): ProposalOutcome
{
    return ProposalOutcome::factory()->for(test()->user)->create(['outcome' => $outcome, 'created_at' => CarbonImmutable::parse($when, 'Europe/Madrid'), ...$values]);
}

test('the six metrics, by week and by class', function () {
    ended(ProposalOutcome::SENT, ['seconds_to_outcome' => 600]);
    ended(ProposalOutcome::EDITED, ['edit_size' => 12, 'seconds_to_outcome' => 1800]);
    ended(ProposalOutcome::EDITED, ['edit_size' => 30, 'seconds_to_outcome' => 3600, 'class' => 'call']);
    ended(ProposalOutcome::DISCARDED, ['reason' => 'not_for_me', 'seconds_to_outcome' => 120]);
    ended(ProposalOutcome::EXPIRED, ['class' => 'task']);
    ended(ProposalOutcome::DURATION_CHANGED, ['class' => 'call']);
    ended(ProposalOutcome::SENT, [], '2026-10-07 10:00');
    $this->user->slackTriageDecisions()->create(['external_id' => 'x', 'action' => 'ignore', 'place' => 'channel', 'owner' => 'other', 'reason' => 'r', 'channel' => '#producto', 'candidate' => [], 'missed_at' => now()]);

    $metrics = app(AlertMetrics::class)->for($this->user, 2);
    [$last, $current] = $metrics['weeks'];

    expect($last)->toMatchArray(['week' => '2026-10-05', 'total' => 1, 'acceptance' => 1.0, 'missed' => 0])
        ->and($current)->toMatchArray([
            'week' => '2026-10-12',
            'total' => 5,
            'acceptance' => 0.6,
            'edit_rate' => 0.67,
            'edit_size' => 21,
            'median_minutes' => 20,
            'expired' => 0.2,
            'missed' => 1,
        ])
        ->and($current['discards'])->toBe(['not_important' => 0.0, 'not_for_me' => 0.2, 'bad_proposal' => 0.0, 'none' => 0.0])
        ->and(collect($metrics['classes'])->keyBy('class')->map(fn (array $measure): array => [$measure['total'], $measure['acceptance']])->all())
        ->toBe(['reply' => [4, 0.75], 'call' => [1, 1.0], 'task' => [1, 0.0]]);
});

test('with nothing yet, the rates are empty rather than zero', function () {
    $week = app(AlertMetrics::class)->for($this->user, 1)['weeks'][0];

    expect($week)->toMatchArray(['total' => 0, 'acceptance' => null, 'edit_rate' => null, 'median_minutes' => null, 'expired' => null]);
});

test('the impact page shows them', function () {
    ended(ProposalOutcome::SENT);

    $this->actingAs($this->user)->get(route('settings.impact.index'))
        ->assertInertia(fn ($page) => $page->component('settings/impact')->has('alerts.weeks', 6)->where('alerts.weeks.5.total', 1)->where('alerts.classes.0.class', 'reply'));
});
