<?php

namespace App\Voice\Tools\Calendar;

use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\CalendarReading;
use App\Connectors\Calendar\EventQuery;
use App\Models\User;
use App\Voice\Tools\ToolContext;

/**
 * Shared arguments and reading for the calendar tools.
 */
trait ReadsCalendar
{
    private const MAX_DAYS = 62;

    private const MAX_EVENTS_FOR_ASSISTANT = 40;

    public function __construct(private CalendarReader $calendar) {}

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'start_date' => [
                    'type' => 'string',
                    'description' => 'Primer día, en formato YYYY-MM-DD. Calcúlalo a partir de la fecha de hoy ("mañana", "el jueves", "la semana que viene").',
                ],
                'end_date' => [
                    'type' => 'string',
                    'description' => 'Último día incluido, en formato YYYY-MM-DD. Omítelo para un solo día. Para "esta semana", del lunes al domingo.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Solo eventos cuyo título contenga este texto, por ejemplo "daily" o "prepago".',
                ],
                'attendee' => [
                    'type' => 'string',
                    'description' => 'Solo eventos con esta persona (nombre o email), por ejemplo "Marta".',
                ],
            ],
            'required' => ['start_date'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'title' => ['nullable', 'string', 'max:100'],
            'attendee' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function query(array $arguments, ToolContext $context): EventQuery
    {
        return EventQuery::days(
            $arguments['start_date'],
            $arguments['end_date'] ?? null,
            $context->timezone(),
            $arguments['title'] ?? null,
            $arguments['attendee'] ?? null,
        );
    }

    private function exceedsMaxRange(EventQuery $query): bool
    {
        return $query->from->diffInDays($query->to) >= self::MAX_DAYS;
    }

    private function read(User $user, EventQuery $query): CalendarReading
    {
        return $this->calendar->read($user, $query);
    }

    /**
     * What the model reads about the events.
     *
     * @return array<string, mixed>
     */
    private function summary(CalendarReading $reading, EventQuery $query): array
    {
        $severalAccounts = count($reading->accounts) > 1;
        $severalCalendars = count($reading->calendars) > count($reading->accounts);
        $events = array_slice($reading->events, 0, self::MAX_EVENTS_FOR_ASSISTANT);

        return array_filter([
            'range' => ['from' => $query->from->toDateString(), 'to' => $query->to->toDateString()],
            'filters' => array_filter(['title' => $query->title, 'attendee' => $query->attendee]) ?: null,
            'total_events' => count($reading->events),
            'events' => array_map(fn ($event): array => $event->toAssistant($severalAccounts, $severalCalendars), $events),
            'truncated' => count($reading->events) > count($events) ?: null,
            'unreadable_accounts' => $reading->failedAccounts ?: null,
            'note' => $reading->accounts === [] && $reading->failedAccounts === []
                ? 'El usuario no tiene ningún calendario conectado. Dile que puede conectarlo en Configuración.'
                : null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
