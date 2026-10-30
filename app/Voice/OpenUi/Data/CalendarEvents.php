<?php

namespace App\Voice\OpenUi\Data;

use App\Connectors\Calendar\CalendarEvent;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\EventQuery;
use App\Models\User;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The person's calendar as rows, one per event: to count meetings by day,
 * add up the hours by calendar or person, or see who organises what.
 */
class CalendarEvents implements Dataset
{
    /** The longest range read at once, in days. */
    private const MAX_DAYS = 62;

    public function __construct(private CalendarReader $calendar) {}

    public function name(): string
    {
        return 'calendar_events';
    }

    public function description(): string
    {
        return 'Eventos del calendario como filas (title, day, weekday, start, minutes, calendar, organizer, attendees, mine, declined, all_day), de from a to (YYYY-MM-DD, como mucho 62 días). '
            .'Para horas por día: @ByDay(rows, "day", "minutes") y divide entre 60.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Primer día, YYYY-MM-DD.'],
                'to' => ['type' => 'string', 'description' => 'Último día, YYYY-MM-DD.'],
                'title' => ['type' => 'string', 'description' => 'Solo los que tengan esto en el título.'],
                'attendee' => ['type' => 'string', 'description' => 'Solo aquellos en los que está esta persona.'],
            ],
            'required' => ['from', 'to'],
        ];
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'title' => ['nullable', 'string', 'max:120'],
            'attendee' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function fields(): array
    {
        return [
            'title' => 'text',
            'day' => 'date',
            'weekday' => 'text',
            'start' => 'text',
            'minutes' => 'number',
            'calendar' => 'text',
            'organizer' => 'text',
            'attendees' => 'number',
            'mine' => 'boolean',
            'declined' => 'boolean',
            'all_day' => 'boolean',
        ];
    }

    public function rows(User $user, array $arguments, string $timezone): array
    {
        if (CarbonImmutable::parse($arguments['from'])->diffInDays(CarbonImmutable::parse($arguments['to'])) >= self::MAX_DAYS) {
            throw new RuntimeException('Como mucho '.self::MAX_DAYS.' días de calendario a la vez.');
        }

        $query = EventQuery::days($arguments['from'], $arguments['to'], $timezone, $arguments['title'] ?? null, $arguments['attendee'] ?? null);

        return array_map(fn (CalendarEvent $event): array => [
            'title' => $event->title,
            'day' => $event->start->toDateString(),
            'weekday' => $event->start->locale('es')->isoFormat('dddd'),
            'start' => $event->allDay ? '' : $event->start->format('H:i'),
            'minutes' => $event->allDay ? 0 : (int) $event->start->diffInMinutes($event->end),
            'calendar' => $event->calendar ?? $event->account,
            'organizer' => $event->organizer ?? '',
            'attendees' => count($event->attendees),
            'mine' => $event->isOrganizer,
            'declined' => $event->declined,
            'all_day' => $event->allDay,
        ], array_values(array_filter($this->calendar->read($user, $query)->events, $query->matches(...))));
    }
}
