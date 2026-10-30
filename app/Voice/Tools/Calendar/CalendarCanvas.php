<?php

namespace App\Voice\Tools\Calendar;

use App\Connectors\Calendar\CalendarEvent;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\EventQuery;
use App\Models\User;
use App\Voice\OpenUi\Views\CalendarAgendaView;
use App\Voice\Tools\Canvas;
use Carbon\CarbonImmutable;

/**
 * The calendar the person already sees from show_calendar, built for a range
 * so another canvas can sit beside it.
 */
final class CalendarCanvas
{
    public function __construct(private CalendarReader $reader) {}

    public function covering(User $user, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $now): Canvas
    {
        $query = new EventQuery(
            $from->timezone($now->getTimezone())->startOfDay(),
            $to->timezone($now->getTimezone())->endOfDay(),
        );
        $reading = $this->reader->read($user, $query);

        if (! $query->isSingleDay()) {
            return CalendarAgendaView::canvas($user, $this->title($query, $now), $reading, $query);
        }

        return new Canvas(
            view: 'calendar.day',
            props: [
                'title' => $this->title($query, $now),
                'from' => $query->from->toDateString(),
                'to' => $query->to->toDateString(),
                'timezone' => $query->timezone(),
                'filters' => [],
                'accounts' => $reading->accounts,
                'calendars' => $reading->calendars,
                'failed_accounts' => $reading->failedAccounts,
                'events' => array_map(fn (CalendarEvent $event): array => $event->toCanvas(), $reading->events),
            ],
        );
    }

    private function title(EventQuery $query, CarbonImmutable $now): string
    {
        $from = $query->from->locale('es');

        if ($query->isSingleDay()) {
            return match (true) {
                $from->isSameDay($now) => 'Hoy, '.$from->isoFormat('D [de] MMMM'),
                $from->isSameDay($now->addDay()) => 'Mañana, '.$from->isoFormat('D [de] MMMM'),
                default => ucfirst($from->isoFormat('dddd, D [de] MMMM')),
            };
        }

        $to = $query->to->locale('es');

        return $from->isSameMonth($to)
            ? "Del {$from->isoFormat('D')} al {$to->isoFormat('D [de] MMMM')}"
            : "Del {$from->isoFormat('D [de] MMMM')} al {$to->isoFormat('D [de] MMMM')}";
    }
}
