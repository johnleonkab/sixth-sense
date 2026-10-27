<?php

namespace App\Connectors\Google;

use App\Connections\AccessTokens;
use App\Connections\Capability;
use App\Connectors\Calendar\CalendarEvent;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\CalendarReading;
use App\Connectors\Calendar\EventQuery;
use App\Connectors\Docs\DocumentLinks;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Reads every calendar the user can see in each Google account that may show
 * the calendar (their own, shared with them, team calendars…), in parallel,
 * and merges them into one timeline.
 *
 * @see https://developers.google.com/calendar/api/v3/reference/calendarList/list
 * @see https://developers.google.com/calendar/api/v3/reference/events/list
 */
class GoogleCalendarReader implements CalendarReader
{
    private const CALENDAR_LIST_URL = 'https://www.googleapis.com/calendar/v3/users/me/calendarList';

    private const EVENTS_URL = 'https://www.googleapis.com/calendar/v3/calendars/%s/events';

    /** Calendars change rarely: list them at most every 10 minutes. */
    private const CALENDAR_LIST_TTL = 600;

    private const MAX_CALENDARS_PER_ACCOUNT = 30;

    private const MAX_RESULTS = 250;

    public function __construct(private AccessTokens $tokens) {}

    public function read(User $user, EventQuery $query): CalendarReading
    {
        $failed = [];
        $sources = [];

        foreach ($user->accountsWith(Capability::CalendarRead) as $account) {
            try {
                $token = $this->tokens->for($account);
            } catch (Throwable $exception) {
                report($exception);
                $failed[] = $account->email;

                continue;
            }

            foreach ($this->calendars($account, $token) as $calendar) {
                $sources[] = ['account' => $account->email, 'account_id' => $account->id, 'token' => $token, 'calendar' => $calendar];
            }
        }

        $responses = $sources === [] ? [] : Http::pool(fn (Pool $pool): array => array_map(
            fn (int $index): mixed => $pool->as((string) $index)
                ->withToken($sources[$index]['token'])
                ->acceptJson()
                ->timeout(10)
                ->get(sprintf(self::EVENTS_URL, rawurlencode($sources[$index]['calendar']['id'])), [
                    'timeMin' => $query->from->toRfc3339String(),
                    'timeMax' => $query->to->addSecond()->toRfc3339String(),
                    'timeZone' => $query->timezone(),
                    'singleEvents' => 'true',
                    'orderBy' => 'startTime',
                    'maxResults' => self::MAX_RESULTS,
                ]),
            array_keys($sources),
        ));

        $events = [];
        $calendars = [];

        foreach ($responses as $index => $response) {
            ['account' => $email, 'account_id' => $accountId, 'calendar' => $calendar] = $sources[(int) $index];

            if (! $response instanceof Response || $response->failed()) {
                report(new RuntimeException("Google Calendar [{$calendar['id']}] of [{$email}] could not be read: ".($response instanceof Response ? $response->status() : 'connection error')));

                // Losing the primary calendar means losing the account; any other is a partial gap.
                if ($calendar['primary'] && ! in_array($email, $failed, true)) {
                    $failed[] = $email;
                }

                continue;
            }

            $calendars[] = ['account' => $email, 'name' => $calendar['name'], 'color' => $calendar['color']];

            foreach ($response->json('items', []) as $item) {
                $event = $this->toEvent($item, $email, $calendar, $query->timezone(), $accountId);

                if ($event !== null && $query->matches($event)) {
                    $events[$event->uniqueKey ?? $event->id] ??= $event;
                }
            }
        }

        $events = array_values($events);
        usort($events, fn (CalendarEvent $a, CalendarEvent $b): int => [$a->start, ! $a->allDay] <=> [$b->start, ! $b->allDay]);

        return new CalendarReading(
            events: $events,
            accounts: array_values(array_unique(array_diff(array_column($sources, 'account'), $failed))),
            failedAccounts: $failed,
            calendars: $calendars,
        );
    }

    public function event(User $user, string $ref, string $timezone): ?CalendarEvent
    {
        $parsed = GoogleEventRef::parse($ref);
        $account = $parsed ? $user->accountsWith(Capability::CalendarRead)->firstWhere('id', $parsed->accountId) : null;

        if ($account === null) {
            return null;
        }

        $response = Http::withToken($this->tokens->for($account))->acceptJson()->timeout(10)
            ->get(sprintf(self::EVENTS_URL, rawurlencode($parsed->calendarId)).'/'.rawurlencode($parsed->eventId), ['timeZone' => $timezone]);

        if ($response->failed()) {
            return null;
        }

        $calendar = ['id' => $parsed->calendarId, 'name' => (string) $account->email, 'color' => null, 'primary' => $parsed->calendarId === 'primary'];

        return $this->toEvent($response->json(), (string) $account->email, $calendar, $timezone, $account->id);
    }

    public function busy(User $user, array $emails, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $account = $user->accountsWith(Capability::CalendarRead)->first();

        if ($account === null || $emails === []) {
            return [];
        }

        try {
            $response = Http::withToken($this->tokens->for($account))->acceptJson()->timeout(10)
                ->post('https://www.googleapis.com/calendar/v3/freeBusy', [
                    'timeMin' => $from->toRfc3339String(),
                    'timeMax' => $to->toRfc3339String(),
                    'items' => array_map(fn (string $email): array => ['id' => $email], array_values(array_unique($emails))),
                ]);
        } catch (ConnectionException $exception) {
            report($exception);

            return [];
        }

        $busy = [];

        foreach ($response->json('calendars', []) as $email => $calendar) {
            if (! empty($calendar['errors'])) {
                continue;
            }

            $busy[$email] = array_map(
                fn (array $period): array => [CarbonImmutable::parse($period['start']), CarbonImmutable::parse($period['end'])],
                $calendar['busy'] ?? [],
            );
        }

        return $busy;
    }

    /**
     * Calendars of the account whose events can be read, primary first. People
     * who only share their free/busy are left out: their events can not be listed.
     *
     * @return list<array{id: string, name: string, color: ?string, primary: bool}>
     */
    private function calendars(ConnectedAccount $account, string $token): array
    {
        $key = "google-calendars:{$account->id}";
        $primaryOnly = [['id' => 'primary', 'name' => $account->email ?? 'Calendario', 'color' => null, 'primary' => true]];

        if (Cache::has($key)) {
            return Cache::get($key);
        }

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(10)->get(self::CALENDAR_LIST_URL, [
                'minAccessRole' => 'reader',
                'maxResults' => 250,
            ]);
        } catch (ConnectionException $exception) {
            report($exception);

            return $primaryOnly;
        }

        if ($response->failed()) {
            report(new RuntimeException("Google calendar list of [{$account->email}] failed with HTTP {$response->status()}."));

            return $primaryOnly;
        }

        $calendars = collect($response->json('items', []))
            ->reject(fn (array $item): bool => $item['deleted'] ?? false)
            ->map(fn (array $item): array => [
                'id' => $item['id'],
                'name' => $item['summaryOverride'] ?? $item['summary'] ?? $item['id'],
                'color' => $item['backgroundColor'] ?? null,
                'primary' => $item['primary'] ?? false,
            ])
            ->sortByDesc('primary')
            ->take(self::MAX_CALENDARS_PER_ACCOUNT)
            ->values()
            ->all();

        $calendars = $calendars === [] ? $primaryOnly : $calendars;

        Cache::put($key, $calendars, self::CALENDAR_LIST_TTL);

        return $calendars;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{id: string, name: string, color: ?string, primary: bool}  $calendar
     */
    private function toEvent(array $item, string $account, array $calendar, string $timezone, int $accountId = 0): ?CalendarEvent
    {
        if (($item['status'] ?? null) === 'cancelled') {
            return null;
        }

        $allDay = isset($item['start']['date']);

        $start = $allDay
            ? CarbonImmutable::parse($item['start']['date'], $timezone)->startOfDay()
            : CarbonImmutable::parse($item['start']['dateTime'])->setTimezone($timezone);

        $end = $allDay
            ? CarbonImmutable::parse($item['end']['date'], $timezone)->startOfDay()
            : CarbonImmutable::parse($item['end']['dateTime'])->setTimezone($timezone);

        $attendees = array_values(array_filter(array_map(
            fn (array $attendee): ?array => ($attendee['resource'] ?? false) ? null : [
                'name' => $attendee['displayName'] ?? $attendee['email'] ?? '',
                'email' => $attendee['email'] ?? null,
                'response' => $attendee['responseStatus'] ?? null,
            ],
            $item['attendees'] ?? [],
        )));

        // "self" is the owner of the calendar the event was read from: only on
        // the user's own calendar does it mean the user declined.
        $self = collect($item['attendees'] ?? [])->firstWhere('self', true);

        return new CalendarEvent(
            id: $item['id'],
            title: $item['summary'] ?? '(Sin título)',
            start: $start,
            end: $end,
            allDay: $allDay,
            account: $account,
            location: $item['location'] ?? null,
            attendees: $attendees,
            organizer: $item['organizer']['displayName'] ?? $item['organizer']['email'] ?? null,
            declined: $calendar['primary'] && ($self['responseStatus'] ?? null) === 'declined',
            meetingUrl: $item['hangoutLink'] ?? null,
            url: $item['htmlLink'] ?? null,
            uniqueKey: isset($item['iCalUID']) ? $item['iCalUID'].'@'.$start->toIso8601String() : null,
            calendar: $calendar['name'],
            calendarColor: $calendar['color'],
            documents: $this->documents($item),
            ref: GoogleEventRef::make($accountId, $calendar['id'], $item['id']),
            description: $item['description'] ?? null,
            isOrganizer: (bool) ($item['organizer']['self'] ?? false),
            myResponse: $calendar['primary'] ? ($self['responseStatus'] ?? null) : null,
            flexible: $this->flexible($item, $account),
        );
    }

    /**
     * Google Docs attached to the event or linked in its description.
     *
     * @param  array<string, mixed>  $item
     * @return list<array{title: ?string, url: string}>
     */
    private function documents(array $item): array
    {
        $documents = [];

        foreach ($item['attachments'] ?? [] as $attachment) {
            if (($attachment['mimeType'] ?? null) === 'application/vnd.google-apps.document' && isset($attachment['fileUrl'])) {
                $documents[DocumentLinks::idFrom($attachment['fileUrl']) ?? $attachment['fileUrl']] = ['title' => $attachment['title'] ?? null, 'url' => $attachment['fileUrl']];
            }
        }

        foreach (DocumentLinks::in($item['description'] ?? null) as $link) {
            $documents[$link['id']] ??= ['title' => null, 'url' => $link['url']];
        }

        return array_values($documents);
    }

    /**
     * A block the person can shorten: one sixth-sense created, or a focus
     * block they organised with nobody else and no call.
     *
     * @param  array<string, mixed>  $item
     */
    private function flexible(array $item, string $account): bool
    {
        if (($item['extendedProperties']['private']['sixthSense'] ?? null) === 'task') {
            return true;
        }

        $others = array_filter(
            $item['attendees'] ?? [],
            fn (array $attendee): bool => ! ($attendee['self'] ?? false) && ! ($attendee['resource'] ?? false) && strcasecmp((string) ($attendee['email'] ?? ''), $account) !== 0,
        );

        return (bool) ($item['organizer']['self'] ?? false) && empty($item['hangoutLink']) && $others === [];
    }
}
