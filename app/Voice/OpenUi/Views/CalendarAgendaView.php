<?php

namespace App\Voice\OpenUi\Views;

use App\Connectors\Calendar\CalendarEvent;
use App\Connectors\Calendar\CalendarReading;
use App\Connectors\Calendar\EventQuery;
use App\Models\User;
use App\Voice\OpenUi\ViewBuilder;
use App\Voice\Tools\Canvas;

/**
 * Events of several days, or the result of a search, grouped by day. When
 * there are several calendars, each line takes its calendar's colour and
 * says where it comes from; an account that could not be read says so.
 */
class CalendarAgendaView
{
    public static function canvas(User $user, string $title, CalendarReading $reading, EventQuery $query): Canvas
    {
        $view = new ViewBuilder($title);
        $several = count($reading->calendars) > 1;
        $accounts = count($reading->accounts) > 1;
        $filters = array_values(array_filter([$query->title ? "«{$query->title}»" : null, $query->attendee ? "con {$query->attendee}" : null]));

        if ($filters !== []) {
            $view->add('Chips', [$filters]);
        }

        $days = [];

        foreach ($reading->events as $event) {
            $day = ucfirst($event->start->locale('es')->isoFormat('dddd D [de] MMMM'));
            $days[$day][] = self::event($event, $several ? $event->calendar : ($accounts ? $event->account : null), $several);
        }

        $view->add('Timeline', [
            array_map(fn (string $label, array $events): array => ['label' => $label, 'events' => $events], array_keys($days), $days),
            $filters !== [] ? 'Ningún evento coincide.' : 'No tienes nada en el calendario.',
        ]);

        if ($reading->failedAccounts !== []) {
            $view->add('Text', ['No he podido leer '.implode(', ', $reading->failedAccounts).'. Revísalo en Configuración.', 'warn']);
        }

        $source = match (true) {
            $several => 'Calendar · '.count($reading->calendars).' calendarios',
            $accounts => 'Calendar · '.count($reading->accounts).' cuentas',
            default => 'Calendar',
        };

        return $view->canvas($user, $source, 'wide');
    }

    /**
     * @return array<string, mixed>
     */
    private static function event(CalendarEvent $event, ?string $origin, bool $coloured): array
    {
        $people = array_map(fn (array $attendee): string => strtok($attendee['name'], ' ') ?: $attendee['name'], $event->attendees);
        $who = count($people) > 3 ? implode(', ', array_slice($people, 0, 3)).' y '.(count($people) - 3).' más' : implode(', ', $people);

        return array_filter([
            'time' => $event->allDay ? 'Todo el día' : $event->start->format('H:i').'–'.$event->end->format('H:i'),
            'title' => $event->title,
            'detail' => implode(' · ', array_filter([$who, $event->location, $origin])) ?: null,
            'color' => $coloured ? $event->calendarColor : null,
            'declined' => $event->declined ?: null,
            'ref' => 'day:'.$event->start->toDateString(),
            'documents' => array_map(fn (array $document): array => ['title' => $document['title'] ?? 'Documento', 'url' => $document['url']], $event->documents) ?: null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
