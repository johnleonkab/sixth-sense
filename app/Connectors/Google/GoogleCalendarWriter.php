<?php

namespace App\Connectors\Google;

use App\Connections\AccessTokens;
use App\Connections\Capability;
use App\Connectors\Calendar\CalendarAccount;
use App\Connectors\Calendar\CalendarWriter;
use App\Connectors\Calendar\CreatedEvent;
use App\Connectors\Calendar\EventUpdate;
use App\Connectors\Calendar\NewEvent;
use App\Connectors\Mail\MailAddress;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @see https://developers.google.com/calendar/api/v3/reference/events
 */
class GoogleCalendarWriter implements CalendarWriter
{
    private const CALENDARS_URL = 'https://www.googleapis.com/calendar/v3/calendars';

    public function __construct(private AccessTokens $tokens) {}

    public function accounts(User $user): array
    {
        return $user->accountsWith(Capability::CalendarWrite)
            ->map(fn (ConnectedAccount $account): CalendarAccount => new CalendarAccount($account->id, (string) $account->email))
            ->values()
            ->all();
    }

    public function canChange(User $user, string $ref): bool
    {
        $parsed = GoogleEventRef::parse($ref);

        return $parsed !== null && collect($this->accounts($user))->contains('accountId', $parsed->accountId);
    }

    public function invite(User $user, string $ref, array $people): void
    {
        [$http, $url] = $this->eventRequest($user, $ref);
        [$event, $etag] = $this->current($http, $url);

        $attendees = $event['attendees'] ?? [];
        $known = array_map(fn (array $attendee): string => strtolower($attendee['email'] ?? ''), $attendees);

        foreach ($people as $person) {
            if (! in_array(strtolower((string) $person->email), $known, true)) {
                $attendees[] = array_filter(['email' => $person->email, 'displayName' => $person->name]);
            }
        }

        $this->ensureOk($http->withHeaders(['If-Match' => $etag])->patch($url.'?sendUpdates=all', ['attendees' => $attendees]), 'invitar');
    }

    public function addSummary(User $user, string $ref, string $summary, string $heading): void
    {
        [$http, $url] = $this->eventRequest($user, $ref);
        [$event, $etag] = $this->current($http, $url);

        $description = trim(($event['description'] ?? '')."\n\n— {$heading} —\n".trim($summary));

        $this->ensureOk($http->withHeaders(['If-Match' => $etag])->patch($url.'?sendUpdates=none', ['description' => $description]), 'añadir el resumen');
    }

    public function create(User $user, NewEvent $event): CreatedEvent
    {
        $account = $this->account($user, $event->accountId);
        $timezone = $event->start->getTimezone()->getName();

        $response = Http::withToken($this->tokens->for($account))->acceptJson()->timeout(20)
            ->post(self::CALENDARS_URL.'/primary/events?sendUpdates=all&conferenceDataVersion=1', array_filter([
                'summary' => $event->title,
                'description' => $event->description,
                'start' => ['dateTime' => $event->start->toIso8601String(), 'timeZone' => $timezone],
                'end' => ['dateTime' => $event->end->toIso8601String(), 'timeZone' => $timezone],
                'attendees' => array_map(fn (MailAddress $person): array => array_filter(['email' => $person->email, 'displayName' => $person->name]), $event->attendees),
                'conferenceData' => $event->videoCall
                    ? ['createRequest' => ['requestId' => (string) Str::uuid(), 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]]
                    : null,
                // Blocks of the person's own work are marked, so they can be told apart (and moved) later.
                'extendedProperties' => $event->task ? ['private' => ['sixthSense' => 'task']] : null,
                'reminders' => $event->task ? ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => 5]]] : null,
            ]));

        $this->ensureOk($response, 'crear la reunión');

        return new CreatedEvent(GoogleEventRef::make($account->id, 'primary', (string) $response->json('id')), $response->json('htmlLink'));
    }

    public function update(User $user, string $ref, EventUpdate $changes): void
    {
        [$http, $url] = $this->eventRequest($user, $ref);
        [$event, $etag] = $this->current($http, $url);

        // Keep the event's own timezone when it has one.
        $time = fn (CarbonImmutable $moment): array => ['dateTime' => $moment->toIso8601String(), 'timeZone' => $event['start']['timeZone'] ?? $moment->getTimezone()->getName()];
        $removed = array_map('strtolower', $changes->removeAttendees);

        $patch = array_filter([
            'summary' => $changes->title,
            'location' => $changes->location,
            'description' => $changes->description,
            'start' => $changes->start ? $time($changes->start) : null,
            'end' => $changes->end ? $time($changes->end) : null,
            'attendees' => $removed === [] ? null : array_values(array_filter(
                $event['attendees'] ?? [],
                fn (array $attendee): bool => ! in_array(strtolower($attendee['email'] ?? ''), $removed, true),
            )),
        ], fn (mixed $value): bool => $value !== null);

        $this->ensureOk(
            $http->withHeaders(['If-Match' => $etag])->patch($url.'?sendUpdates='.($changes->notify ? 'all' : 'none'), $patch),
            'cambiar la reunión',
        );
    }

    public function cancel(User $user, string $ref): void
    {
        [$http, $url] = $this->eventRequest($user, $ref);

        $this->ensureOk($http->delete($url.'?sendUpdates=all'), 'cancelar la reunión');
    }

    public function respond(User $user, string $ref, string $response, ?string $comment = null): void
    {
        // Answers always go on the user's own copy of the event.
        $parsed = GoogleEventRef::parse($ref) ?? throw new RuntimeException('Evento no válido.');
        [$http, $url] = $this->eventRequest($user, GoogleEventRef::make($parsed->accountId, 'primary', $parsed->eventId));
        [$event, $etag] = $this->current($http, $url);

        $found = false;
        $attendees = array_map(function (array $attendee) use ($response, $comment, &$found): array {
            if ($attendee['self'] ?? false) {
                $found = true;

                return array_filter([...$attendee, 'responseStatus' => $response, 'comment' => $comment ?? ($attendee['comment'] ?? null)]);
            }

            return $attendee;
        }, $event['attendees'] ?? []);

        if (! $found) {
            throw new RuntimeException('No estás invitado a esta reunión, así que no hay invitación que responder.');
        }

        $this->ensureOk($http->withHeaders(['If-Match' => $etag])->patch($url.'?sendUpdates=all', ['attendees' => $attendees]), 'responder a la invitación');
    }

    private function account(User $user, int $accountId): ConnectedAccount
    {
        return $user->accountsWith(Capability::CalendarWrite)->firstWhere('id', $accountId)
            ?? throw new RuntimeException('Esta cuenta no tiene permiso para cambiar el calendario. Actívalo en Configuración y vuelve a conectarla.');
    }

    /**
     * @return array{PendingRequest, string}
     */
    private function eventRequest(User $user, string $ref): array
    {
        $parsed = GoogleEventRef::parse($ref) ?? throw new RuntimeException('Evento no válido.');
        $account = $this->account($user, $parsed->accountId);

        return [
            Http::withToken($this->tokens->for($account))->acceptJson()->timeout(20),
            self::CALENDARS_URL.'/'.rawurlencode($parsed->calendarId).'/events/'.rawurlencode($parsed->eventId),
        ];
    }

    /**
     * The event as it is now, and its version, so the change fails instead of
     * overwriting someone else's edit made in between.
     *
     * @return array{array<string, mixed>, string}
     */
    private function current(PendingRequest $http, string $url): array
    {
        $response = $http->get($url);
        $this->ensureOk($response, 'leer la reunión');

        return [$response->json(), (string) $response->json('etag')];
    }

    private function ensureOk(Response $response, string $what): void
    {
        if ($response->status() === 412) {
            throw new RuntimeException('La reunión ha cambiado mientras tanto. Vuelve a pedírmelo.');
        }

        if ($response->failed()) {
            throw new RuntimeException("Google Calendar no ha dejado {$what}: ".($response->json('error.message') ?? "HTTP {$response->status()}"));
        }
    }
}
