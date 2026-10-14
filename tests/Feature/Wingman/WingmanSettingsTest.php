<?php

use App\Connections\Capability;
use App\Connectors\Calendar\CalendarEvent;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\CalendarReading;
use App\Connectors\Calendar\EventQuery;
use App\Connectors\Calendar\FakeCalendarReader;
use App\Events\WingmanMeetingStarted;
use App\Events\WingmanMeetingUpdated;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\WingmanMeeting;
use App\Models\WingmanSegment;
use App\Wingman\Closing\MeetingCloser;
use App\Wingman\ExtensionTokens;
use App\Wingman\MeetingStatus;
use App\Wingman\WingmanCalls;
use App\Wingman\WingmanMeetings;
use App\Wingman\WingmanSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'Europe/Madrid'));
    config(['agenda.timezone' => 'Europe/Madrid']);
    Event::fake([WingmanMeetingStarted::class, WingmanMeetingUpdated::class]);
    $this->user = User::factory()->create(['wingman_enabled' => true]);
    ConnectedAccount::factory()->for($this->user)->create(['email' => 'trabajo@cm.com', 'scopes' => [Capability::CalendarRead->scope()]]);
    ConnectedAccount::factory()->for($this->user)->create(['email' => 'personal@gmail.com', 'scopes' => [Capability::CalendarRead->scope()]]);
});

function settings(array $data)
{
    return test()->actingAs(test()->user)->put(route('settings.wingman.settings'), [...['calendars' => null, 'never_listen' => [], 'keep_transcript' => false], ...$data]);
}

test('the settings are kept, and only real calendars of the person are accepted', function () {
    settings(['calendars' => ['trabajo@cm.com'], 'never_listen' => ['1:1', ' RRHH ', '1:1', ''], 'keep_transcript' => true])->assertRedirect()->assertSessionHasNoErrors();

    expect(WingmanSettings::for($this->user->refresh())->toArray())->toBe(['calendars' => ['trabajo@cm.com'], 'never_listen' => ['1:1', 'RRHH'], 'keep_transcript' => true]);

    settings(['calendars' => ['otra@persona.com']])->assertSessionHasErrors('calendars.0');

    $this->actingAs($this->user)->get(route('settings.wingman.edit'))
        ->assertInertia(fn ($page) => $page->where('settings.never_listen', ['1:1', 'RRHH'])->where('calendars', fn ($calendars) => collect($calendars)->sort()->values()->all() === ['personal@gmail.com', 'trabajo@cm.com']));
});

test('only the calendars chosen are followed', function () {
    $this->app->instance(CalendarReader::class, new class extends FakeCalendarReader
    {
        public function read(User $user, EventQuery $query): CalendarReading
        {
            $event = fn (string $id, string $account, string $code) => new CalendarEvent(id: $id, title: "Reunión {$id}", start: now()->addHour()->toImmutable(), end: now()->addHours(2)->toImmutable(), allDay: false, account: $account, meetingUrl: "https://meet.google.com/{$code}");

            return new CalendarReading(events: [$event('a', 'trabajo@cm.com', 'aaa-aaaa-aaa'), $event('b', 'personal@gmail.com', 'bbb-bbbb-bbb')], accounts: ['trabajo@cm.com', 'personal@gmail.com']);
        }
    });
    Queue::fake();
    $this->user->update(['wingman_settings' => (new WingmanSettings(calendars: ['TRABAJO@cm.com']))->toArray()]);

    app(WingmanMeetings::class)->sync($this->user);

    expect($this->user->wingmanMeetings()->pluck('meeting_code')->all())->toBe(['aaa-aaaa-aaa']);
});

test('a meeting a rule says never to listen to starts paused, keeps nothing, and can be listened to this once', function () {
    Queue::fake();
    $this->user->update(['wingman_settings' => (new WingmanSettings(neverListen: ['1:1']))->toArray()]);
    $meeting = WingmanMeeting::factory()->for($this->user)->create(['title' => '1:1 con Marta', 'meeting_code' => 'one-oone-mrt', 'starts_at' => now(), 'ends_at' => now()->addMinutes(30)]);
    $token = app(ExtensionTokens::class)->issue($this->user);

    $this->withToken($token)->postJson('/api/wingman/extension/calls', ['code' => 'one-oone-mrt', 'joined_at' => now()->toIso8601String()])->assertOk();

    expect($meeting->refresh()->paused_at)->not->toBeNull()
        ->and($meeting->paused_reason)->toBe('regla «1:1»');
    $this->withToken($token)->postJson("/api/wingman/extension/calls/{$meeting->id}/segments", ['segments' => [['client_id' => 'a', 'speaker' => 'Marta', 'text' => 'Algo personal', 'spoken_at' => now()->toIso8601String()]]])
        ->assertJson(['taken' => 0, 'paused' => true]);
    $this->actingAs($this->user)->getJson(route('wingman.live'))->assertJsonPath('meeting.paused_reason', 'regla «1:1»');

    // "Escuchar esta vez".
    $this->actingAs($this->user)->postJson(route('wingman.pause', $meeting->id), ['paused' => false])->assertJsonPath('meeting.paused_reason', null);
    expect($meeting->segments()->count())->toBe(0);
});

test('joining again keeps the choice made in the call, rule or not', function () {
    Queue::fake();
    $this->user->update(['wingman_settings' => (new WingmanSettings(neverListen: ['1:1']))->toArray()]);
    $meeting = WingmanMeeting::factory()->for($this->user)->create(['title' => '1:1 con Marta', 'meeting_code' => 'one-oone-mrt', 'starts_at' => now(), 'ends_at' => now()->addMinutes(30)]);
    app(WingmanCalls::class)->start($this->user, 'one-oone-mrt', CarbonImmutable::now());
    app(WingmanCalls::class)->pause($meeting->refresh(), false);

    app(WingmanCalls::class)->start($this->user, 'one-oone-mrt', CarbonImmutable::now()->addMinute());

    expect($meeting->refresh()->paused_at)->toBeNull();
});

test('what was said is deleted when the meeting closes, unless the person keeps it', function (bool $keep, int $left) {
    Queue::fake();
    $this->user->update(['wingman_settings' => (new WingmanSettings(keepTranscript: $keep))->toArray()]);
    $meeting = WingmanMeeting::factory()->for($this->user)->create(['status' => MeetingStatus::Ended]);
    WingmanSegment::factory()->for($meeting, 'meeting')->count(3)->create(['noted' => true]);

    app(MeetingCloser::class)->close($meeting);

    expect($meeting->segments()->count())->toBe($left)
        ->and($meeting->refresh()->closed_at)->not->toBeNull();
})->with([
    'by default, deleted' => [false, 0],
    'kept if wanted' => [true, 3],
]);

test('past meetings can be searched by title, attendee or what they were about', function () {
    WingmanMeeting::factory()->for($this->user)->create(['title' => 'Revisión flujo de prepago', 'starts_at' => now()->subDays(2), 'attendees' => [['name' => 'Marta Ruiz']]]);
    WingmanMeeting::factory()->for($this->user)->create(['title' => 'Sync ventas', 'starts_at' => now()->subDays(3), 'attendees' => [['name' => 'Pablo Gil']], 'notes' => ['summary' => 'Hablamos del contrato con Grupo Vela.']]);

    $search = fn (string $q) => $this->actingAs($this->user)->get(route('wingman.index', ['q' => $q]));

    $search('marta')->assertInertia(fn ($page) => $page->where('q', 'marta')->has('past', 1)->where('past.0.title', 'Revisión flujo de prepago'));
    $search('contrato vela')->assertInertia(fn ($page) => $page->has('past', 1)->where('past.0.title', 'Sync ventas'));
    $search('nada')->assertInertia(fn ($page) => $page->has('past', 0));
});

test('"abre las notas de la reunión con Marta" finds it by its attendees', function () {
    $marta = WingmanMeeting::factory()->for($this->user)->create(['title' => 'Revisión flujo de prepago', 'joined_at' => now()->subDay(), 'attendees' => [['name' => 'Marta Ruiz']]]);
    WingmanMeeting::factory()->for($this->user)->create(['title' => 'Sync ventas', 'joined_at' => now()->subHour(), 'attendees' => [['name' => 'Pablo Gil']]]);

    $this->actingAs($this->user)->postJson('/voice/tools/review_meeting', ['arguments' => ['title' => 'la reunión con Marta'], 'timezone' => 'Europe/Madrid'])
        ->assertOk()
        ->assertJsonPath('output.meeting_id', $marta->id);
});
