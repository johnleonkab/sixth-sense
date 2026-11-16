<?php

use App\Comms\Evaluation\FixtureRunner;
use App\Comms\Events\Projections;
use App\Comms\Events\Replay;
use App\Models\Comms\CommsLinkDecision;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsWindow;
use App\Models\User;

test('the phase 0 fixture replays twice to the same rows', function () {
    $user = User::factory()->create();
    $runner = app(FixtureRunner::class);
    $path = base_path('tests/Fixtures/Comms/phase-0-sequence.php');

    $runner->check($user, $path);
    $runner->check($user, $path);

    expect(app(Projections::class)->capture($user))->toBe([]);
});

test('replay clears linking rows and capture lists them before that', function () {
    $user = User::factory()->create();
    $window = CommsWindow::query()->create([
        'user_id' => $user->id,
        'source' => 'slack',
        'group_ref' => 'C1',
        'kind' => 'channel',
        'message_ids' => [1],
        'opened_at' => '2026-11-08 09:00:00',
        'last_message_at' => '2026-11-08 09:05:00',
    ]);
    $segment = CommsSegment::query()->create([
        'user_id' => $user->id,
        'window_id' => $window->id,
        'message_ids' => [1],
        'new_message_ids' => [1],
        'participants' => ['person:1'],
        'restated' => 'Revisar el prepago',
        'unresolved_refs' => [],
    ]);
    CommsLinkDecision::query()->create([
        'user_id' => $user->id,
        'segment_id' => $segment->id,
        'outcome' => 'new',
        'certainty' => 'sure',
        'agreement' => '3/3',
        'quote' => 'prepago',
        'run_event_ids' => [],
    ]);

    $captured = app(Projections::class)->capture($user);

    expect($captured['windows'][0]['group_ref'])->toBe('C1')
        ->and($captured['segments'][0]['restated'])->toBe('Revisar el prepago')
        ->and($captured['link_decisions'][0]['quote'])->toBe('prepago');

    app(Replay::class)->run($user);

    expect(CommsWindow::query()->count())->toBe(0)
        ->and(CommsSegment::query()->count())->toBe(0)
        ->and(CommsLinkDecision::query()->count())->toBe(0)
        ->and(app(Projections::class)->capture($user))->toBe([]);
});
