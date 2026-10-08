<?php

namespace App\Voice\Tools\Calendar;

use App\Actions\Actions;
use App\Connectors\Calendar\CalendarEvent;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\CalendarWriter;
use App\Connectors\Mail\RecipientResolver;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\Docs\ProposesChanges;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;

/**
 * Shared steps for changing an event: find it, check the user may change it,
 * and show the change as a draft until the person says "sí".
 */
trait ProposesCalendarChanges
{
    use ProposesChanges;

    public function __construct(
        private CalendarReader $reader,
        private CalendarWriter $writer,
        private Actions $actions,
        private RecipientResolver $recipients,
    ) {}

    /**
     * The event to change, or an error for the assistant.
     */
    private function changeableEvent(User $user, string $ref, ToolContext $context, bool $mustOrganize): CalendarEvent|ToolResult
    {
        $event = $this->reader->event($user, $ref, $context->timezone());

        if ($event === null) {
            return ToolResult::error('No encuentro ese evento. Búscalo de nuevo en el calendario para obtener su event_id.');
        }

        if (! $this->writer->canChange($user, $ref)) {
            return ToolResult::error('Esta cuenta no tiene permiso para cambiar el calendario. El usuario puede activarlo en Configuración (Conexiones) y volver a conectar la cuenta.');
        }

        if ($mustOrganize && ! $event->isOrganizer) {
            return ToolResult::error("El usuario no organiza «{$event->title}» (la organiza {$event->organizer}), así que no puede cambiarla. Puede responder a la invitación o pedírselo al organizador.");
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $payload  What the change needs to run.
     * @param  array<string, mixed>  $details  What the screen shows about the change.
     */
    private function proposeChange(User $user, string $kind, string $label, array $payload, ?CalendarEvent $event, array $details): ToolResult
    {
        $action = $this->actions->propose($user, 'calendar.change', $label, ['kind' => $kind, ...$payload]);

        return new ToolResult(
            output: $this->proposalOutput($action, ['change' => $label, ...array_filter($details, fn (mixed $value): bool => is_scalar($value))]),
            canvas: new Canvas('calendar.change', [
                'action' => $action->toState(),
                'kind' => $kind,
                'event' => $event ? $this->card($event) : null,
                ...$details,
            ]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function card(CalendarEvent $event): array
    {
        return [
            'title' => $event->title,
            'start' => $event->start->toIso8601String(),
            'end' => $event->end->toIso8601String(),
            'all_day' => $event->allDay,
            'location' => $event->location,
            'organizer' => $event->organizer,
            'attendees' => $event->attendees,
            'meeting_url' => $event->meetingUrl,
            'url' => $event->url,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function eventIdParameter(): array
    {
        return ['type' => 'string', 'description' => 'event_id del evento, de find_calendar_events o show_calendar.'];
    }
}
