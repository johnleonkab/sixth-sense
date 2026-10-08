<?php

use App\Connections\Capability;
use App\Connectors\Calendar\EventQuery;
use App\Connectors\Google\GoogleCalendarReader;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function googleEvent(array $overrides = []): array
{
    return [
        'id' => 'evt-'.fake()->unique()->numberBetween(1, 99999),
        'iCalUID' => fake()->uuid().'@google.com',
        'status' => 'confirmed',
        'summary' => 'Daily de producto',
        'start' => ['dateTime' => '2026-10-07T09:30:00+02:00'],
        'end' => ['dateTime' => '2026-10-07T09:45:00+02:00'],
        'attendees' => [
            ['email' => 'marta@example.com', 'displayName' => 'Marta Ruiz', 'responseStatus' => 'accepted'],
            ['email' => 'jhon@work.com', 'self' => true, 'responseStatus' => 'accepted'],
            ['email' => 'sala@resource.calendar.google.com', 'resource' => true],
        ],
        'organizer' => ['email' => 'marta@example.com', 'displayName' => 'Marta Ruiz'],
        'hangoutLink' => 'https://meet.google.com/abc-defg-hij',
        'htmlLink' => 'https://www.google.com/calendar/event?eid=abc',
        ...$overrides,
    ];
}

/**
 * Answer each account's requests by access token. Events are either a list
 * (for the primary calendar) or keyed by calendar id; an int is an HTTP error.
 *
 * @param  array<string, mixed>  $eventsByToken
 * @param  array<string, list<array<string, mixed>>|int>  $calendarsByToken
 */
function fakeCalendars(array $eventsByToken, array $calendarsByToken = []): void
{
    Http::fake(function (Request $request) use ($eventsByToken, $calendarsByToken) {
        $token = str($request->header('Authorization')[0] ?? '')->after('Bearer ')->toString();

        if (str_contains($request->url(), '/users/me/calendarList')) {
            $calendars = $calendarsByToken[$token] ?? [['id' => 'primary', 'summary' => 'Principal', 'primary' => true, 'backgroundColor' => '#4285f4']];

            return is_int($calendars) ? Http::response([], $calendars) : Http::response(['items' => $calendars]);
        }

        $calendarId = rawurldecode(str($request->url())->between('/calendars/', '/events')->toString());
        $events = $eventsByToken[$token] ?? [];

        if (is_array($events) && ! array_is_list($events)) {
            $events = $events[$calendarId] ?? [];
        }

        return is_int($events) ? Http::response([], $events) : Http::response(['items' => $events]);
    });
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->work = ConnectedAccount::factory()->for($this->user)->create(['email' => 'jhon@work.com', 'access_token' => 'work-token']);
    $this->personal = ConnectedAccount::factory()->for($this->user)->create(['email' => 'jhon@gmail.com', 'access_token' => 'personal-token']);
    $this->query = EventQuery::days('2026-10-07', null, 'Europe/Madrid');
});

test('it reads the primary calendar of every account for the whole day in the user timezone', function () {
    fakeCalendars([]);

    app(GoogleCalendarReader::class)->read($this->user, $this->query);

    Http::assertSentCount(4);
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://www.googleapis.com/calendar/v3/calendars/primary/events')
        && $request['timeMin'] === '2026-10-07T00:00:00+02:00'
        && $request['timeMax'] === '2026-10-08T00:00:00+02:00'
        && $request['timeZone'] === 'Europe/Madrid'
        && $request['singleEvents'] === 'true');
});

test('events from every account are merged in one timeline', function () {
    fakeCalendars([
        'work-token' => [googleEvent(['summary' => '1:1 con Marta', 'start' => ['dateTime' => '2026-10-07T12:00:00+02:00'], 'end' => ['dateTime' => '2026-10-07T12:30:00+02:00']])],
        'personal-token' => [googleEvent(['summary' => 'Dentista', 'start' => ['dateTime' => '2026-10-07T08:00:00Z'], 'end' => ['dateTime' => '2026-10-07T09:00:00Z']])],
    ]);

    $reading = app(GoogleCalendarReader::class)->read($this->user, $this->query);

    expect(array_map(fn ($event) => $event->title, $reading->events))->toBe(['Dentista', '1:1 con Marta'])
        ->and($reading->events[0]->start->format('H:i'))->toBe('10:00')
        ->and($reading->events[0]->account)->toBe('jhon@gmail.com')
        ->and($reading->accounts)->toEqualCanonicalizing(['jhon@work.com', 'jhon@gmail.com']);
});

test('an event both accounts are invited to appears once', function () {
    $shared = googleEvent(['iCalUID' => 'shared@google.com']);

    fakeCalendars(['work-token' => [$shared], 'personal-token' => [[...$shared, 'id' => 'other-id']]]);

    expect(app(GoogleCalendarReader::class)->read($this->user, $this->query)->events)->toHaveCount(1);
});

test('it maps all-day, cancelled and declined events and leaves rooms out of attendees', function () {
    fakeCalendars(['work-token' => [
        googleEvent(['summary' => 'Vacaciones', 'start' => ['date' => '2026-10-07'], 'end' => ['date' => '2026-10-08']]),
        googleEvent(['summary' => 'Cancelada', 'status' => 'cancelled']),
        googleEvent(['summary' => 'Rechazada', 'attendees' => [['email' => 'jhon@work.com', 'self' => true, 'responseStatus' => 'declined']]]),
        googleEvent(),
    ]]);

    $events = collect(app(GoogleCalendarReader::class)->read($this->user, $this->query)->events)->keyBy('title');

    expect($events->keys()->all())->toEqualCanonicalizing(['Vacaciones', 'Rechazada', 'Daily de producto'])
        ->and($events['Vacaciones']->allDay)->toBeTrue()
        ->and($events['Vacaciones']->start->toIso8601String())->toBe('2026-10-07T00:00:00+02:00')
        ->and($events['Rechazada']->declined)->toBeTrue()
        ->and($events['Daily de producto']->attendees)->toBe([
            ['name' => 'Marta Ruiz', 'email' => 'marta@example.com', 'response' => 'accepted'],
            ['name' => 'jhon@work.com', 'email' => 'jhon@work.com', 'response' => 'accepted'],
        ])
        ->and($events['Daily de producto']->ref)->toBe("{$this->work->id}:primary:{$events['Daily de producto']->id}")
        ->and($events['Daily de producto']->myResponse)->toBe('accepted')
        ->and($events['Daily de producto']->meetingUrl)->toBe('https://meet.google.com/abc-defg-hij');
});

test('it filters by title and attendee ignoring case and accents', function () {
    fakeCalendars(['work-token' => [
        googleEvent(['summary' => 'Revisión prepago']),
        googleEvent(['summary' => 'Sync ventas', 'attendees' => [['email' => 'pablo@example.com', 'displayName' => 'Pablo Gil']], 'organizer' => ['email' => 'pablo@example.com']]),
    ]]);

    $reader = app(GoogleCalendarReader::class);

    expect($reader->read($this->user, EventQuery::days('2026-10-07', null, 'Europe/Madrid', title: 'REVISION'))->events)->toHaveCount(1)
        ->and($reader->read($this->user, EventQuery::days('2026-10-07', null, 'Europe/Madrid', attendee: 'pablo'))->events[0]->title)->toBe('Sync ventas');
});

test('accounts that can not be read are reported and the rest still work', function () {
    ConnectedAccount::factory()->for($this->user)->needsReconnection()->create(['email' => 'old@gmail.com']);
    $this->personal->update(['expires_at' => now()->subMinute()]);
    fakeCalendars(['work-token' => [googleEvent()]]);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $reading = app(GoogleCalendarReader::class)->read($this->user, $this->query);

    expect($reading->events)->toHaveCount(1)
        ->and($reading->accounts)->toBe(['jhon@work.com'])
        ->and($reading->failedAccounts)->toBe(['jhon@gmail.com']);
});

test('accounts with the calendar switched off are not read', function () {
    $this->personal->toggleCapability(Capability::CalendarRead, false);
    fakeCalendars([]);

    app(GoogleCalendarReader::class)->read($this->user, $this->query);

    Http::assertSentCount(2);
});

test('it reads every calendar the account can see, not only the primary', function () {
    fakeCalendars(
        ['work-token' => [
            'primary' => [googleEvent(['summary' => 'Daily'])],
            'equipo@group.calendar.google.com' => [googleEvent(['summary' => 'Release', 'start' => ['dateTime' => '2026-10-07T15:00:00+02:00'], 'end' => ['dateTime' => '2026-10-07T16:00:00+02:00']])],
        ]],
        ['work-token' => [
            ['id' => 'equipo@group.calendar.google.com', 'summary' => 'Equipo producto', 'summaryOverride' => 'Producto', 'backgroundColor' => '#16a765'],
            ['id' => 'primary', 'summary' => 'jhon@work.com', 'primary' => true, 'backgroundColor' => '#4285f4'],
        ]],
    );
    $this->personal->toggleCapability(Capability::CalendarRead, false);

    $reading = app(GoogleCalendarReader::class)->read($this->user, $this->query);
    $release = collect($reading->events)->firstWhere('title', 'Release');

    expect($reading->events)->toHaveCount(2)
        ->and($release->calendar)->toBe('Producto')
        ->and($release->calendarColor)->toBe('#16a765')
        ->and($reading->calendars)->toEqualCanonicalizing([
            ['account' => 'jhon@work.com', 'name' => 'jhon@work.com', 'color' => '#4285f4'],
            ['account' => 'jhon@work.com', 'name' => 'Producto', 'color' => '#16a765'],
        ]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/users/me/calendarList')
        && $request['minAccessRole'] === 'reader');
});

test('declining only counts on the user own calendar', function () {
    $declinedBySelf = ['attendees' => [['email' => 'x@example.com', 'self' => true, 'responseStatus' => 'declined']]];

    fakeCalendars(
        ['work-token' => [
            'primary' => [googleEvent(['summary' => 'Mía', ...$declinedBySelf])],
            'marta@example.com' => [googleEvent(['summary' => 'De Marta', ...$declinedBySelf])],
        ]],
        ['work-token' => [
            ['id' => 'primary', 'summary' => 'jhon@work.com', 'primary' => true],
            ['id' => 'marta@example.com', 'summary' => 'Marta Ruiz'],
        ]],
    );

    $events = collect(app(GoogleCalendarReader::class)->read($this->user, $this->query)->events)->keyBy('title');

    expect($events['Mía']->declined)->toBeTrue()
        ->and($events['De Marta']->declined)->toBeFalse();
});

test('the calendar list is cached between reads', function () {
    fakeCalendars([]);
    $reader = app(GoogleCalendarReader::class);

    $reader->read($this->user, $this->query);
    $reader->read($this->user, $this->query);

    Http::assertSentCount(6);
});

test('if the calendar list fails it still reads the primary calendar', function () {
    fakeCalendars(['work-token' => [googleEvent()]], ['work-token' => 500]);

    $reading = app(GoogleCalendarReader::class)->read($this->user, $this->query);

    expect($reading->events)->toHaveCount(1)
        ->and($reading->failedAccounts)->toBe([]);
});

test('a secondary calendar that fails leaves a gap but keeps the account', function () {
    fakeCalendars(
        ['work-token' => ['primary' => [googleEvent()], 'roto@group.calendar.google.com' => 403]],
        ['work-token' => [
            ['id' => 'primary', 'summary' => 'jhon@work.com', 'primary' => true],
            ['id' => 'roto@group.calendar.google.com', 'summary' => 'Roto'],
        ]],
    );

    $reading = app(GoogleCalendarReader::class)->read($this->user, $this->query);

    expect($reading->events)->toHaveCount(1)
        ->and($reading->failedAccounts)->toBe([])
        ->and($reading->accounts)->toContain('jhon@work.com');
});
