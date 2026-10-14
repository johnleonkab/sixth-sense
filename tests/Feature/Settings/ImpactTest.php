<?php

use App\Analytics\Impact;
use App\Models\AgendaTask;
use App\Models\PendingAction;
use App\Models\User;
use App\Scheduling\AgendaTaskStatus;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'Europe/Madrid'));
    config(['agenda.timezone' => 'Europe/Madrid']);
    $this->user = User::factory()->create();
});

function sent(User $user, string $type, int $daysAgo = 0, array $payload = [], string $status = 'executed'): PendingAction
{
    return PendingAction::factory()->for($user)->create(['type' => $type, 'payload' => $payload, 'status' => $status, 'executed_at' => $status === 'executed' ? now()->subDays($daysAgo) : null]);
}

function task(User $user, AgendaTaskStatus $status, int $daysAgo = 0, array $extra = []): AgendaTask
{
    $task = AgendaTask::factory()->for($user)->create(['status' => $status, 'starts_at' => in_array($status, [AgendaTaskStatus::Scheduled, AgendaTaskStatus::Proposed], true) ? now()->addDay() : null, ...$extra]);
    $task->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

    return $task;
}

test('each proposal that went out saves a minute, each slot found two, and events are counted', function () {
    sent($this->user, 'chat.send');
    sent($this->user, 'mail.send');
    sent($this->user, 'chat.send');
    sent($this->user, 'calendar.change', payload: ['kind' => 'create']);
    sent($this->user, 'calendar.change', payload: ['kind' => 'cancel']);
    // Not saved: cancelled, undone, still waiting, failed.
    sent($this->user, 'chat.send', status: 'cancelled');
    sent($this->user, 'chat.send', status: 'undone');
    sent($this->user, 'chat.send', status: 'pending');
    sent($this->user, 'chat.send', status: 'failed');

    task($this->user, AgendaTaskStatus::Scheduled, extra: ['event_ref' => 'google:1']);
    task($this->user, AgendaTaskStatus::Proposed, extra: ['pending_action_id' => sent($this->user, 'calendar.change', status: 'pending')->id]);
    task($this->user, AgendaTaskStatus::Conflict);
    task($this->user, AgendaTaskStatus::Failed);

    expect(app(Impact::class)->summary($this->user))->toBe([
        // 5 proposals × 1 + 2 slots × 2.
        'minutes' => 9,
        'proposals' => 5,
        'slots' => 2,
        // A meeting created on a "sí", and a task of the person's booked.
        'events' => 2,
        'by_service' => [
            ['service' => 'Slack', 'count' => 2],
            ['service' => 'Calendario', 'count' => 2],
            ['service' => 'Email', 'count' => 1],
        ],
    ]);
});

test('the period counts only what happened in it, and the weeks show where the minutes went', function () {
    sent($this->user, 'chat.send', daysAgo: 1);
    sent($this->user, 'chat.send', daysAgo: 20);
    task($this->user, AgendaTaskStatus::Scheduled, daysAgo: 8, extra: ['event_ref' => 'google:1']);

    expect(app(Impact::class)->summary($this->user, now()->subDays(7)->toImmutable())['minutes'])->toBe(1)
        ->and(app(Impact::class)->summary($this->user, now()->subDays(30)->toImmutable())['minutes'])->toBe(4);

    $weeks = app(Impact::class)->weekly($this->user, 4, 'Europe/Madrid');

    expect(array_column($weeks, 'week'))->toBe(['2026-09-21', '2026-09-28', '2026-10-05', '2026-10-12'])
        ->and(array_column($weeks, 'minutes'))->toBe([1, 0, 2, 1]);
});

test('the impact page shows the chosen period', function () {
    sent($this->user, 'jira.create', daysAgo: 10);
    sent($this->user, 'notion.comment');

    $this->actingAs($this->user)->get(route('settings.impact.index'))
        ->assertInertia(fn ($page) => $page->component('settings/impact')->where('period', '30')->where('summary.proposals', 2)->has('weekly', 8)->where('rates', ['proposal' => 1, 'slot' => 2]));

    $this->actingAs($this->user)->get(route('settings.impact.index', ['period' => '7']))
        ->assertInertia(fn ($page) => $page->where('period', '7')->where('summary.proposals', 1)->where('summary.by_service', [['service' => 'Notion', 'count' => 1]]));

    $this->actingAs($this->user)->get(route('settings.impact.index', ['period' => 'forever']))->assertSessionHasErrors('period');
});

test('only the person\'s own work counts', function () {
    sent(User::factory()->create(), 'chat.send');

    expect(app(Impact::class)->summary($this->user)['proposals'])->toBe(0);
});
