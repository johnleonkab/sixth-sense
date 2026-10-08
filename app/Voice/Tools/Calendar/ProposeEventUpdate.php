<?php

namespace App\Voice\Tools\Calendar;

use App\Connectors\Calendar\CalendarEvent;
use App\Connectors\Calendar\EventUpdate;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Proposes changing an event the user organises: another day or time, another
 * title or place, a new description, or fewer people. Nothing changes until
 * the person confirms.
 */
class ProposeEventUpdate implements VoiceTool
{
    use ProposesCalendarChanges;

    public function name(): string
    {
        return 'propose_event_update';
    }

    public function description(): string
    {
        return 'Propone cambiar una reunión que organiza el usuario: moverla de día o de hora ("muévela al jueves a las 10", "retrásala media hora"), cambiar su duración, su título, el lugar o la descripción, o quitar invitados. '
            .'Se muestra como borrador con el antes y el después, y NO cambia nada hasta que el usuario lo confirme. Indica solo lo que cambia: lo demás se queda igual, y si mueves la hora sin decir la duración, se mantiene. '
            .'Para añadir invitados usa propose_event_invite; para cancelarla, propose_event_cancellation; si no la organiza, solo puede responder con propose_event_response.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => self::eventIdParameter(),
                'date' => ['type' => 'string', 'description' => 'Nuevo día, YYYY-MM-DD.'],
                'start_time' => ['type' => 'string', 'description' => 'Nueva hora de inicio, HH:MM (24 h).'],
                'end_time' => ['type' => 'string', 'description' => 'Nueva hora de fin, HH:MM.'],
                'duration_minutes' => ['type' => 'integer', 'description' => 'Nueva duración en minutos, si no da hora de fin.'],
                'title' => ['type' => 'string', 'description' => 'Nuevo título.'],
                'location' => ['type' => 'string', 'description' => 'Nuevo lugar.'],
                'description' => ['type' => 'string', 'description' => 'Nueva descripción completa (sustituye la actual).'],
                'remove_people' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Invitados a quitar: nombres o emails.'],
                'notify' => ['type' => 'boolean', 'description' => 'Avisar a los invitados del cambio. Por defecto, sí.'],
            ],
            'required' => ['event_id'],
        ];
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:500'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'title' => ['nullable', 'string', 'max:300'],
            'location' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:8000'],
            'remove_people' => ['nullable', 'array', 'max:50'],
            'remove_people.*' => ['string', 'max:200'],
            'notify' => ['nullable', 'boolean'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $event = $this->changeableEvent($user, $arguments['event_id'], $context, mustOrganize: true);

        if ($event instanceof ToolResult) {
            return $event;
        }

        $moves = isset($arguments['date']) || isset($arguments['start_time']) || isset($arguments['end_time']) || isset($arguments['duration_minutes']);

        if ($moves && $event->allDay) {
            return ToolResult::error("«{$event->title}» dura todo el día: no puedo cambiarle la hora desde aquí. Puedes cambiar su título, lugar o descripción.");
        }

        [$start, $end] = $moves ? $this->newTimes($event, $arguments, $context->timezone()) : [null, null];

        if ($end !== null && $end->lessThanOrEqualTo($start)) {
            return ToolResult::error('La hora de fin tiene que ser posterior a la de inicio.');
        }

        try {
            $removed = $this->attendeesToRemove($event, $arguments['remove_people'] ?? []);
        } catch (RuntimeException $exception) {
            return ToolResult::error($exception->getMessage());
        }

        $changes = new EventUpdate(
            start: $start,
            end: $end,
            title: filled($arguments['title'] ?? null) && $arguments['title'] !== $event->title ? $arguments['title'] : null,
            location: filled($arguments['location'] ?? null) ? $arguments['location'] : null,
            description: filled($arguments['description'] ?? null) ? $arguments['description'] : null,
            removeAttendees: array_column($removed, 'email'),
            notify: (bool) ($arguments['notify'] ?? true),
        );

        if ($changes->isEmpty()) {
            return ToolResult::error('No has indicado ningún cambio para la reunión.');
        }

        $conflicts = $start ? $this->conflicts($user, $start, $end, except: $event->ref) : [];
        $diff = $this->diff($event, $changes, $removed, $context->timezone());

        return $this->proposeChange(
            $user,
            'update',
            ($start ? 'Mover' : 'Cambiar')." «{$event->title}»",
            ['ref' => $event->ref, 'changes' => $changes->toArray()],
            $event,
            [
                'changes' => $diff,
                'summary' => implode('; ', array_map(fn (array $change): string => "{$change['label']}: {$change['after']}", $diff)),
                'updated_event' => [
                    ...$this->card($event),
                    'title' => $changes->title ?? $event->title,
                    'start' => ($start ?? $event->start)->toIso8601String(),
                    'end' => ($end ?? $event->end)->toIso8601String(),
                    'location' => $changes->location ?? $event->location,
                    'attendees' => array_values(array_filter($event->attendees, fn (array $attendee): bool => ! in_array($attendee['email'], $changes->removeAttendees, true))),
                ],
                'notify' => $changes->notify,
                'conflicts' => array_map(fn (CalendarEvent $other): array => [
                    'title' => $other->title,
                    'start' => $other->start->toIso8601String(),
                    'end' => $other->end->toIso8601String(),
                ], $conflicts),
                'conflict_count' => count($conflicts),
            ],
        );
    }

    /**
     * The new start and end: what was said, keeping the rest (same day, same
     * time, same length) as it was.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function newTimes(CalendarEvent $event, array $arguments, string $timezone): array
    {
        $current = $event->start->setTimezone($timezone);
        $date = $arguments['date'] ?? $current->toDateString();
        $start = CarbonImmutable::parse($date.' '.($arguments['start_time'] ?? $current->format('H:i')), $timezone);

        $end = match (true) {
            isset($arguments['end_time']) => CarbonImmutable::parse("{$date} {$arguments['end_time']}", $timezone),
            isset($arguments['duration_minutes']) => $start->addMinutes((int) $arguments['duration_minutes']),
            default => $start->addSeconds((int) $event->start->diffInSeconds($event->end)),
        };

        return [$start, $end];
    }

    /**
     * Attendees of the event the person named, by name or email.
     *
     * @param  list<string>  $names
     * @return list<array{name: ?string, email: string}>
     */
    private function attendeesToRemove(CalendarEvent $event, array $names): array
    {
        $normalise = fn (?string $value): string => Str::lower(Str::ascii(trim((string) $value)));

        return array_map(function (string $name) use ($event, $normalise): array {
            $matches = array_values(array_filter($event->attendees, fn (array $attendee): bool => $attendee['email'] !== null
                && ($normalise($attendee['email']) === $normalise($name) || Str::contains($normalise($attendee['name']).' '.$normalise($attendee['email']), $normalise($name)))));

            return match (count($matches)) {
                1 => ['name' => $matches[0]['name'], 'email' => $matches[0]['email']],
                0 => throw new RuntimeException("«{$name}» no está invitado a «{$event->title}»."),
                default => throw new RuntimeException("Hay varios invitados que encajan con «{$name}»: ".implode(', ', array_column($matches, 'name')).'. Pregúntale cuál.'),
            };
        }, $names);
    }

    /**
     * What changes, before and after, as the person reads it.
     *
     * @param  list<array{name: ?string, email: string}>  $removed
     * @return list<array{label: string, before: ?string, after: string}>
     */
    private function diff(CalendarEvent $event, EventUpdate $changes, array $removed, string $timezone): array
    {
        $when = fn (CarbonImmutable $start, CarbonImmutable $end): string => $start->setTimezone($timezone)->locale('es')->isoFormat('dddd D [de] MMMM, HH:mm').'–'.$end->setTimezone($timezone)->format('H:i');

        return array_values(array_filter([
            $changes->start ? ['label' => 'Cuándo', 'before' => $when($event->start, $event->end), 'after' => $when($changes->start, $changes->end)] : null,
            $changes->title ? ['label' => 'Título', 'before' => $event->title, 'after' => $changes->title] : null,
            $changes->location ? ['label' => 'Lugar', 'before' => $event->location, 'after' => $changes->location] : null,
            $changes->description ? ['label' => 'Descripción', 'before' => $event->description ? Str::limit($event->description, 160) : null, 'after' => Str::limit($changes->description, 300)] : null,
            $removed !== [] ? ['label' => 'Sin invitar', 'before' => null, 'after' => implode(', ', array_map(fn (array $person): string => $person['name'] ?? $person['email'], $removed))] : null,
        ]));
    }
}
