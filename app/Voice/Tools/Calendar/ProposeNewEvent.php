<?php

namespace App\Voice\Tools\Calendar;

use App\Connectors\Calendar\CalendarAccount;
use App\Connectors\Calendar\CalendarEvent;
use App\Connectors\Calendar\EventQuery;
use App\Connectors\Calendar\NewEvent;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\RecipientNotResolved;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use Carbon\CarbonImmutable;

class ProposeNewEvent implements VoiceTool
{
    use ProposesCalendarChanges;

    public function name(): string
    {
        return 'propose_new_event';
    }

    public function description(): string
    {
        return 'Propone convocar una reunión nueva en el calendario del usuario, con invitados y videollamada de Google Meet, y la muestra como borrador avisando si choca con algo. '
            .'NO la crea hasta que el usuario lo confirme. Invitados por nombre o email; si son ambiguos, pregunta. '
            .'Si no dice la duración, 30 minutos.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Título de la reunión.'],
                'date' => ['type' => 'string', 'description' => 'Día, YYYY-MM-DD.'],
                'start_time' => ['type' => 'string', 'description' => 'Hora de inicio, HH:MM (24 h).'],
                'end_time' => ['type' => 'string', 'description' => 'Hora de fin, HH:MM. Alternativa a duration_minutes.'],
                'duration_minutes' => ['type' => 'integer', 'description' => 'Duración en minutos, si no da hora de fin.'],
                'people' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Invitados: nombres o emails.'],
                'description' => ['type' => 'string', 'description' => 'Descripción o agenda, opcional.'],
                'video_call' => ['type' => 'boolean', 'description' => 'Añadir Google Meet. Por defecto, sí.'],
                'from_account' => ['type' => 'string', 'description' => 'Email de la cuenta en cuyo calendario crearla, si tiene varias y lo indica.'],
            ],
            'required' => ['title', 'date', 'start_time'],
        ];
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:300'],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'people' => ['nullable', 'array', 'max:50'],
            'people.*' => ['string', 'max:200'],
            'description' => ['nullable', 'string', 'max:8000'],
            'video_call' => ['nullable', 'boolean'],
            'from_account' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $accounts = $this->writer->accounts($user);
        $account = filled($arguments['from_account'] ?? null)
            ? collect($accounts)->first(fn (CalendarAccount $account): bool => strcasecmp($account->email, $arguments['from_account']) === 0)
            : ($accounts[0] ?? null);

        if ($account === null) {
            return ToolResult::error($accounts === []
                ? 'Ninguna cuenta tiene permiso para cambiar el calendario. El usuario puede activarlo en Configuración (Conexiones) y volver a conectar la cuenta.'
                : 'No puede crear reuniones en esa cuenta. Puede en: '.implode(', ', array_map(fn (CalendarAccount $account): string => $account->email, $accounts)).'.');
        }

        try {
            $people = $this->recipients->resolveAll($user, $arguments['people'] ?? []);
        } catch (RecipientNotResolved $exception) {
            return ToolResult::error($exception->getMessage());
        }

        $start = CarbonImmutable::parse("{$arguments['date']} {$arguments['start_time']}", $context->timezone());
        $end = isset($arguments['end_time'])
            ? CarbonImmutable::parse("{$arguments['date']} {$arguments['end_time']}", $context->timezone())
            : $start->addMinutes((int) ($arguments['duration_minutes'] ?? 30));

        $event = new NewEvent($account->accountId, $arguments['title'], $start, $end, $people, $arguments['description'] ?? null, (bool) ($arguments['video_call'] ?? true));
        $conflicts = $this->conflicts($user, $start, $end);

        return $this->proposeChange(
            $user,
            'create',
            "Convocar «{$event->title}»",
            ['event' => $event->toArray()],
            null,
            [
                'new_event' => [
                    'title' => $event->title,
                    'start' => $start->toIso8601String(),
                    'end' => $end->toIso8601String(),
                    'calendar' => $account->email,
                    'attendees' => array_map(fn (MailAddress $person): array => $person->toArray(), $people),
                    'description' => $event->description,
                    'video_call' => $event->videoCall,
                ],
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
     * What the user already has at that time.
     *
     * @return list<CalendarEvent>
     */
    private function conflicts(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $day = $this->reader->read($user, new EventQuery($start->startOfDay(), $start->endOfDay()));

        return array_values(array_filter($day->events, fn (CalendarEvent $event): bool => ! $event->allDay
            && ! $event->declined
            && $event->start->lessThan($end)
            && $event->end->greaterThan($start)));
    }
}
