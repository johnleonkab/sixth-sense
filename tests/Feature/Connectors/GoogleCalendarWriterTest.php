<?php

use App\Connections\Capability;
use App\Connectors\Calendar\NewEvent;
use App\Connectors\Google\GoogleCalendarReader;
use App\Connectors\Google\GoogleCalendarWriter;
use App\Connectors\Mail\MailAddress;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function existingEvent(array $overrides = []): array
{
    return [
        'id' => 'ev1', 'etag' => '"etag-1"', 'status' => 'confirmed', 'summary' => 'Sync producto–ventas',
        'description' => 'Agenda: precios.',
        'start' => ['dateTime' => '2026-10-08T13:00:00+02:00'], 'end' => ['dateTime' => '2026-10-08T14:00:00+02:00'],
        'organizer' => ['email' => 'jhon@work.com', 'self' => true],
        'attendees' => [
            ['email' => 'jhon@work.com', 'self' => true, 'organizer' => true, 'responseStatus' => 'accepted'],
            ['email' => 'pablo@work.com', 'displayName' => 'Pablo Gil', 'responseStatus' => 'needsAction'],
        ],
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = ConnectedAccount::factory()->for($this->user)->create(['email' => 'jhon@work.com', 'access_token' => 'tok']);
    $this->ref = "{$this->account->id}:primary:ev1";
});

test('one event can be read by its ref', function () {
    Http::fake(['*' => Http::response(existingEvent())]);

    $event = app(GoogleCalendarReader::class)->event($this->user, $this->ref, 'Europe/Madrid');

    expect($event->ref)->toBe($this->ref)
        ->and($event->isOrganizer)->toBeTrue()
        ->and($event->description)->toBe('Agenda: precios.')
        ->and($event->attendees[1])->toBe(['name' => 'Pablo Gil', 'email' => 'pablo@work.com', 'response' => 'needsAction']);
});

test('inviting adds people to whoever is there now and notifies them', function () {
    Http::fake(fn (Request $request) => $request->method() === 'GET' ? Http::response(existingEvent()) : Http::response(['id' => 'ev1']));

    app(GoogleCalendarWriter::class)->invite($this->user, $this->ref, [new MailAddress('Marta Ruiz', 'marta@work.com'), new MailAddress(null, 'PABLO@work.com')]);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request->url() === 'https://www.googleapis.com/calendar/v3/calendars/primary/events/ev1?sendUpdates=all'
        && $request->hasHeader('If-Match', '"etag-1"')
        && array_column($request['attendees'], 'email') === ['jhon@work.com', 'pablo@work.com', 'marta@work.com']);
});

test('a summary is added after the existing description', function () {
    Http::fake(fn (Request $request) => $request->method() === 'GET' ? Http::response(existingEvent()) : Http::response([]));

    app(GoogleCalendarWriter::class)->addSummary($this->user, $this->ref, "- Precios en noviembre\n- Pablo prepara la propuesta", 'Resumen · 8 de octubre de 2026');

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '?sendUpdates=none')
        && $request['description'] === "Agenda: precios.\n\n— Resumen · 8 de octubre de 2026 —\n- Precios en noviembre\n- Pablo prepara la propuesta");
});

test('a new meeting is created with google meet and its guests are invited', function () {
    Http::fake(['*' => Http::response(['id' => 'new1', 'htmlLink' => 'https://calendar.google.com/event?eid=new1'])]);

    $url = app(GoogleCalendarWriter::class)->create($this->user, new NewEvent(
        accountId: $this->account->id,
        title: 'Revisión prepago',
        start: CarbonImmutable::parse('2026-10-09 10:00', 'Europe/Madrid'),
        end: CarbonImmutable::parse('2026-10-09 10:30', 'Europe/Madrid'),
        attendees: [new MailAddress('Marta Ruiz', 'marta@work.com')],
    ));

    expect($url)->toBe('https://calendar.google.com/event?eid=new1');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://www.googleapis.com/calendar/v3/calendars/primary/events?sendUpdates=all&conferenceDataVersion=1'
        && $request['summary'] === 'Revisión prepago'
        && $request['start'] === ['dateTime' => '2026-10-09T10:00:00+02:00', 'timeZone' => 'Europe/Madrid']
        && $request['attendees'] === [['email' => 'marta@work.com', 'displayName' => 'Marta Ruiz']]
        && $request['conferenceData']['createRequest']['conferenceSolutionKey'] === ['type' => 'hangoutsMeet']);
});

test('cancelling deletes the meeting and tells the guests', function () {
    Http::fake(['*' => Http::response(null, 204)]);

    app(GoogleCalendarWriter::class)->cancel($this->user, $this->ref);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/events/ev1?sendUpdates=all'));
});

test('answering an invitation only changes the user own response, on their calendar', function () {
    $invite = existingEvent(['organizer' => ['email' => 'marta@work.com'], 'attendees' => [
        ['email' => 'marta@work.com', 'organizer' => true, 'responseStatus' => 'accepted'],
        ['email' => 'jhon@work.com', 'self' => true, 'responseStatus' => 'needsAction'],
    ]]);
    Http::fake(fn (Request $request) => $request->method() === 'GET' ? Http::response($invite) : Http::response([]));

    app(GoogleCalendarWriter::class)->respond($this->user, "{$this->account->id}:marta@work.com:ev1", 'declined', 'Tengo otra reunión');

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_contains($request->url(), '/calendars/primary/events/ev1?sendUpdates=all')
        && $request['attendees'][0]['responseStatus'] === 'accepted'
        && $request['attendees'][1]['responseStatus'] === 'declined'
        && $request['attendees'][1]['comment'] === 'Tengo otra reunión');
});

test('a change made by someone else in between is not overwritten', function () {
    Http::fake(fn (Request $request) => $request->method() === 'GET' ? Http::response(existingEvent()) : Http::response([], 412));

    app(GoogleCalendarWriter::class)->invite($this->user, $this->ref, [new MailAddress(null, 'marta@work.com')]);
})->throws(RuntimeException::class, 'ha cambiado mientras tanto');

test('nothing changes without the calendar permission', function () {
    $this->account->toggleCapability(Capability::CalendarWrite, false);
    Http::fake();

    expect(app(GoogleCalendarWriter::class)->canChange($this->user, $this->ref))->toBeFalse();
    expect(fn () => app(GoogleCalendarWriter::class)->cancel($this->user, $this->ref))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});
