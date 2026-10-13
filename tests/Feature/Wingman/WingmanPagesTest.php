<?php

use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\FakeCalendarReader;
use App\Jobs\PrepareWingmanContext;
use App\Jobs\SyncWingmanMeetings;
use App\Models\User;
use App\Models\WingmanMeeting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:40', 'Europe/Madrid'));
    config(['agenda.timezone' => 'Europe/Madrid']);
    $this->app->instance(CalendarReader::class, new FakeCalendarReader);
    Queue::fake();
});

test("the meetings page looks up today's calls after it opens, and lists past ones", function () {
    $user = User::factory()->create(['wingman_enabled' => true]);
    WingmanMeeting::factory()->for($user)->create(['title' => 'Ayer', 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour()]);

    $this->actingAs($user)->get(route('wingman.index'))
        ->assertInertia(fn ($page) => $page->component('wingman/index')
            ->where('enabled', true)
            ->missing('today')
            ->has('past', 1)
            ->where('past.0.title', 'Ayer')
            ->loadDeferredProps(fn ($reload) => $reload->has('today', 4)->where('today.1.title', 'Revisión flujo de prepago')));
});

test('with wingman off, the page says so and looks nothing up', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('wingman.index'))
        ->assertInertia(fn ($page) => $page->where('enabled', false)->loadDeferredProps(fn ($reload) => $reload->has('today', 0)));

    expect($user->wingmanMeetings()->count())->toBe(0);
});

test('opening a meeting not yet prepared prepares it', function () {
    $meeting = WingmanMeeting::factory()->create();

    $this->actingAs($meeting->user)->get(route('wingman.show', $meeting->id))
        ->assertInertia(fn ($page) => $page->component('wingman/show')
            ->where('meeting.id', $meeting->id)
            ->where('meeting.context', null)
            ->where('meeting.attendees.0.name', 'Marta Ruiz'));

    Queue::assertPushed(PrepareWingmanContext::class, 1);
});

test('a prepared meeting opens as it is, and can be prepared again', function () {
    $meeting = WingmanMeeting::factory()->create(['context' => ['issues' => []], 'context_prepared_at' => now()]);

    $this->actingAs($meeting->user)->get(route('wingman.show', $meeting->id))->assertInertia(fn ($page) => $page->where('meeting.context.issues', []));
    Queue::assertNothingPushed();

    $this->actingAs($meeting->user)->post(route('wingman.prepare', $meeting->id))->assertRedirect();

    expect($meeting->refresh()->context_prepared_at)->toBeNull();
    Queue::assertPushed(PrepareWingmanContext::class, 1);
});

test("someone else's meeting can not be opened", function () {
    $meeting = WingmanMeeting::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('wingman.show', $meeting->id))->assertNotFound();
    $this->actingAs(User::factory()->create())->post(route('wingman.prepare', $meeting->id))->assertNotFound();
});

test('turning wingman on looks up the calls of today at once', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('settings.wingman.edit'))
        ->assertInertia(fn ($page) => $page->component('settings/wingman')->where('enabled', false));

    $this->actingAs($user)->put(route('settings.wingman.update'), ['enabled' => true])->assertRedirect();

    expect($user->refresh()->wingman_enabled)->toBeTrue();
    Queue::assertPushed(SyncWingmanMeetings::class, fn (SyncWingmanMeetings $job) => $job->user->is($user));
});
