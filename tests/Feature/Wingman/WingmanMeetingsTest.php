<?php

use App\Connections\Capability;
use App\Connectors\Calendar\CalendarEvent;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\CalendarReading;
use App\Connectors\Calendar\EventQuery;
use App\Connectors\Calendar\FakeCalendarReader;
use App\Jobs\PrepareWingmanContext;
use App\Jobs\SyncWingmanMeetings;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\WingmanMeeting;
use App\Wingman\MeetingStatus;
use App\Wingman\WingmanMeetings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // A Wednesday, 10:40 in Madrid: the daily is over, the prepago review starts at 11:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:40', 'Europe/Madrid'));
    config(['agenda.timezone' => 'Europe/Madrid']);
    Queue::fake();
    $this->user = User::factory()->create(['wingman_enabled' => true]);
});

/**
 * A calendar that answers these events, for cases the sample day does not have.
 *
 * @param  list<CalendarEvent>  $events
 */
function calendarWith(array $events): CalendarReader
{
    return new class($events) extends FakeCalendarReader
    {
        public function __construct(public array $events) {}

        public function read(User $user, EventQuery $query): CalendarReading
        {
            return new CalendarReading(events: $this->events, accounts: ['jhon@cm.com']);
        }
    };
}

function meetEvent(string $id, string $code, string $from, string $to, array $overrides = []): CalendarEvent
{
    return new CalendarEvent(...[
        'id' => $id,
        'title' => "Reunión {$id}",
        'start' => CarbonImmutable::parse("2026-10-14 {$from}", 'Europe/Madrid'),
        'end' => CarbonImmutable::parse("2026-10-14 {$to}", 'Europe/Madrid'),
        'allDay' => false,
        'account' => 'jhon@cm.com',
        'meetingUrl' => "https://meet.google.com/{$code}",
        'ref' => "google:primary:{$id}",
        ...$overrides,
    ]);
}

test("today's meet calls are kept by their code, and those about to start are prepared", function () {
    $this->app->instance(CalendarReader::class, new FakeCalendarReader);

    $found = app(WingmanMeetings::class)->sync($this->user);

    // The sample day has five events; the one in person (no Meet link) is left out.
    expect($found)->toBe(4)
        ->and($this->user->wingmanMeetings()->orderBy('starts_at')->pluck('meeting_code')->all())->toBe(['dly-prod-uct', 'pre-pago-rev', 'one-oone-mrt', 'syn-pven-tas']);

    $prepago = $this->user->wingmanMeetings()->firstWhere('meeting_code', 'pre-pago-rev');

    expect($prepago->title)->toBe('Revisión flujo de prepago')
        ->and($prepago->status)->toBe(MeetingStatus::Scheduled)
        ->and($prepago->starts_at->equalTo(CarbonImmutable::parse('2026-10-14 09:00', 'UTC')))->toBeTrue()
        ->and($prepago->attendees[0]['name'])->toBe('Marta Ruiz')
        ->and($prepago->description)->toContain('CPD-412');

    // Within the half hour before: the prepago review. The daily is over; the 1:1 is later.
    Queue::assertPushed(PrepareWingmanContext::class, 1);
    Queue::assertPushed(PrepareWingmanContext::class, fn (PrepareWingmanContext $job) => $job->meeting->is($prepago));
});

test('syncing again keeps the same meetings, and drops those cancelled before starting', function () {
    $this->app->instance(CalendarReader::class, calendarWith([
        meetEvent('a', 'aaa-aaaa-aaa', '12:00', '12:30'),
        meetEvent('b', 'bbb-bbbb-bbb', '13:00', '13:30'),
    ]));
    app(WingmanMeetings::class)->sync($this->user);
    app(WingmanMeetings::class)->sync($this->user);

    expect($this->user->wingmanMeetings()->count())->toBe(2);

    // "b" was cancelled; "a" moved to 12:30 (a new start is a new meeting).
    $this->app->instance(CalendarReader::class, calendarWith([meetEvent('a', 'aaa-aaaa-aaa', '12:30', '13:00')]));
    app(WingmanMeetings::class)->sync($this->user);

    expect($this->user->wingmanMeetings()->pluck('meeting_code')->all())->toBe(['aaa-aaaa-aaa'])
        ->and($this->user->wingmanMeetings()->sole()->starts_at->equalTo(CarbonImmutable::parse('2026-10-14 10:30', 'UTC')))->toBeTrue();
});

test('declined, all-day and link-less events are not followed, and a failing account drops nothing', function () {
    WingmanMeeting::factory()->for($this->user)->create(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);
    $this->app->instance(CalendarReader::class, new class([meetEvent('d', 'ddd-dddd-ddd', '12:00', '12:30', ['myResponse' => 'declined']), meetEvent('e', 'eee-eeee-eee', '00:00', '23:59', ['allDay' => true]), meetEvent('f', 'fff-ffff-fff', '12:00', '12:30', ['meetingUrl' => 'https://zoom.us/j/1'])]) extends FakeCalendarReader
    {
        public function __construct(public array $events) {}

        public function read(User $user, EventQuery $query): CalendarReading
        {
            return new CalendarReading(events: $this->events, accounts: ['jhon@cm.com'], failedAccounts: ['otra@cm.com']);
        }
    });

    expect(app(WingmanMeetings::class)->sync($this->user))->toBe(0)
        ->and($this->user->wingmanMeetings()->count())->toBe(1);
});

test('a changed title or description gathers the context again', function () {
    $this->app->instance(CalendarReader::class, calendarWith([meetEvent('a', 'aaa-aaaa-aaa', '11:00', '11:30')]));
    app(WingmanMeetings::class)->sync($this->user);
    $meeting = $this->user->wingmanMeetings()->sole();
    $meeting->update(['context' => ['issues' => []], 'context_prepared_at' => now()]);

    $this->app->instance(CalendarReader::class, calendarWith([meetEvent('a', 'aaa-aaaa-aaa', '11:00', '11:30', ['description' => 'Ahora con CPD-412'])]));
    app(WingmanMeetings::class)->sync($this->user);

    expect($meeting->refresh()->context_prepared_at)->toBeNull();
});

test('every ten minutes, people who turned wingman on and can read a calendar are synced', function () {
    ConnectedAccount::factory()->for($this->user)->create(['scopes' => [Capability::CalendarRead->scope()]]);
    $off = User::factory()->create(['wingman_enabled' => false]);
    ConnectedAccount::factory()->for($off)->create(['scopes' => [Capability::CalendarRead->scope()]]);
    User::factory()->create(['wingman_enabled' => true]);

    $this->artisan('wingman:sync')->assertSuccessful();

    Queue::assertPushed(SyncWingmanMeetings::class, 1);
    Queue::assertPushed(SyncWingmanMeetings::class, fn (SyncWingmanMeetings $job) => $job->user->is($this->user));
});

test('the meet code is read from a meet link, and only from one', function (?string $url, ?string $code) {
    expect(WingmanMeeting::codeIn($url))->toBe($code);
})->with([
    'meet' => ['https://meet.google.com/abc-defg-hij?authuser=1', 'abc-defg-hij'],
    'no link' => [null, null],
    'zoom' => ['https://zoom.us/j/123', null],
]);
