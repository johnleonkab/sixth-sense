<?php

namespace App\Voice\Tools\Calendar;

use App\Connectors\Calendar\CalendarEvent;
use App\Connectors\Calendar\CalendarReading;
use App\Connectors\Calendar\EventQuery;
use App\Models\User;
use App\Voice\OpenUi\Views\CalendarAgendaView;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Shows the calendar on screen: a day timeline, or an agenda for ranges and searches.
 */
class ShowCalendar implements VoiceTool
{
    use ReadsCalendar;

    public function name(): string
    {
        return 'show_calendar';
    }

    public function description(): string
    {
        return 'Muestra el calendario del usuario en pantalla y te devuelve los mismos eventos para que los comentes. '
            .'Úsala cuando pida verlo ("enséñame mi día", "abre el calendario de la semana", "muéstrame las reuniones con Pablo") '
            .'o cuando haya muchos eventos y verlos ayude más que oírlos. '
            .'Como el usuario ya los ve, no los leas todos: resume en una o dos frases lo importante.';
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $query = $this->query($arguments, $context);

        if ($this->exceedsMaxRange($query)) {
            return ToolResult::error('El rango es demasiado largo: pide como mucho dos meses.');
        }

        $reading = $this->read($user, $query);

        return new ToolResult(
            output: [...$this->summary($reading, $query), 'shown_on_screen' => true],
            canvas: $this->canvas($user, $reading, $query, $context),
        );
    }

    private function canvas(User $user, CalendarReading $reading, EventQuery $query, ToolContext $context): Canvas
    {
        // Several days, or a search: grouped by day. One day keeps its time grid.
        if (! $query->isSingleDay() || $query->isFiltered()) {
            return CalendarAgendaView::canvas($user, $this->title($query, $context), $reading, $query);
        }

        return new Canvas(
            view: 'calendar.day',
            props: [
                'title' => $this->title($query, $context),
                'from' => $query->from->toDateString(),
                'to' => $query->to->toDateString(),
                'timezone' => $query->timezone(),
                'filters' => array_filter(['title' => $query->title, 'attendee' => $query->attendee]),
                'accounts' => $reading->accounts,
                'calendars' => $reading->calendars,
                'failed_accounts' => $reading->failedAccounts,
                'events' => array_map(fn (CalendarEvent $event): array => $event->toCanvas(), $reading->events),
            ],
        );
    }

    private function title(EventQuery $query, ToolContext $context): string
    {
        $from = $query->from->locale('es');

        if ($query->isSingleDay()) {
            return match (true) {
                $from->isSameDay($context->now) => 'Hoy, '.$from->isoFormat('D [de] MMMM'),
                $from->isSameDay($context->now->addDay()) => 'Mañana, '.$from->isoFormat('D [de] MMMM'),
                default => ucfirst($from->isoFormat('dddd, D [de] MMMM')),
            };
        }

        $to = $query->to->locale('es');

        return $from->isSameMonth($to)
            ? "Del {$from->isoFormat('D')} al {$to->isoFormat('D [de] MMMM')}"
            : "Del {$from->isoFormat('D [de] MMMM')} al {$to->isoFormat('D [de] MMMM')}";
    }
}
