<?php

namespace App\Voice\Tools\Agenda;

use App\Models\AgendaTask;
use App\Models\User;
use App\Scheduling\AgendaTaskStatus;
use App\Scheduling\Scheduler;
use App\Scheduling\SchedulingRequest;
use App\Scheduling\Urgency;
use App\Voice\OpenUi\Views\AgendaTaskView;
use App\Voice\Tools\Calendar\CalendarCanvas;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * "Agéndame revisar el PRD esta semana": the scheduler decides how urgent it
 * is and how long it takes, finds a slot and books it.
 */
class ScheduleTaskTool implements VoiceTool
{
    public function __construct(private Scheduler $scheduler, private CalendarCanvas $calendar) {}

    public function name(): string
    {
        return 'schedule_task';
    }

    public function description(): string
    {
        return 'El agendador: busca hueco en el calendario del usuario para algo que tiene que hacer ("agéndame revisar el PRD", "búscame una hora para preparar la demo", "necesito hablar con Marta del prepago esta semana"). '
            .'Decide la urgencia (por el plazo, quién lo pide y en qué está trabajando), la duración y si es una tarea suya o una reunión con más gente. '
            .'Las tareas suyas las agenda directamente (se pueden deshacer con cancel_scheduled_task o mover con propose_event_update usando su event_id); las reuniones con otros quedan como borrador hasta que diga "sí". '
            .'Si no hay hueco antes del plazo, díselo y pregúntale qué mover. Cuenta en una frase cuándo queda y por qué esa urgencia.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'what' => ['type' => 'string', 'description' => 'Qué tiene que hacer, empezando por un verbo.'],
                'context' => ['type' => 'string', 'description' => 'Lo que se sepa: de dónde viene, quién lo pide, qué implica, cómo de difícil es.'],
                'effort_minutes' => ['type' => 'integer', 'description' => 'Cuánto le llevará, si lo dice.'],
                'deadline' => ['type' => 'string', 'description' => 'Plazo, YYYY-MM-DD, si lo hay.'],
                'urgency' => ['type' => 'string', 'enum' => array_column(Urgency::cases(), 'value'), 'description' => 'Solo si el usuario dice cuándo ("hoy", "la semana que viene").'],
                'people' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Con quién, si hay que reunirse: nombres o emails.'],
            ],
            'required' => ['what'],
        ];
    }

    public function rules(): array
    {
        return [
            'what' => ['required', 'string', 'max:200'],
            'context' => ['nullable', 'string', 'max:4000'],
            'effort_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'deadline' => ['nullable', 'date_format:Y-m-d'],
            'urgency' => ['nullable', 'in:'.implode(',', array_column(Urgency::cases(), 'value'))],
            'people' => ['nullable', 'array', 'max:20'],
            'people.*' => ['string', 'max:200'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $task = $user->agendaTasks()->create(['origin' => 'voice', 'title' => $arguments['what'], 'context' => $arguments['context'] ?? null]);

        $task = $this->scheduler->schedule($task, new SchedulingRequest(
            title: $arguments['what'],
            context: $arguments['context'] ?? null,
            effortMinutes: $arguments['effort_minutes'] ?? null,
            deadline: $arguments['deadline'] ?? null,
            people: $arguments['people'] ?? [],
            urgency: isset($arguments['urgency']) ? Urgency::from($arguments['urgency']) : null,
        ));

        $when = $task->starts_at?->setTimezone($context->timezone())->locale('es')->isoFormat('dddd D [de] MMMM, HH:mm');

        return new ToolResult(
            output: array_filter([
                'task_id' => $task->id,
                'status' => $task->status->label(),
                'title' => $task->title,
                'urgency' => $task->urgency?->label(),
                'why_that_urgency' => $task->urgency_reason,
                'minutes' => $task->duration_minutes,
                'when' => $when,
                'event_id' => $task->event_ref,
                'pending_action_id' => $task->pending_action_id,
                'say' => match ($task->status) {
                    AgendaTaskStatus::Scheduled => "Agendado: «{$task->title}», {$when}.",
                    AgendaTaskStatus::Proposed => "Te propongo «{$task->title}» el {$when}. Es un borrador: necesita tu «sí» para enviar las invitaciones.",
                    AgendaTaskStatus::Conflict => "No encuentro hueco para «{$task->title}» a tiempo. Te he dejado un aviso: dime qué muevo.",
                    default => $task->error,
                },
            ], fn (mixed $value): bool => $value !== null),
            canvas: AgendaTaskView::canvas($user, $task, $context->timezone()),
            beside: $task->status === AgendaTaskStatus::Conflict ? $this->calendarBeside($user, $task, $context) : null,
        );
    }

    /**
     * The days that were searched, when none of them had room.
     */
    private function calendarBeside(User $user, AgendaTask $task, ToolContext $context): ?Canvas
    {
        try {
            $until = $task->deadline !== null
                ? CarbonImmutable::parse($task->deadline->toDateString(), $context->timezone())->endOfDay()
                : ($task->urgency?->latestEnd($context->now) ?? $context->now->endOfWeek());

            return $this->calendar->covering($user, $context->now, $until, $context->now);
        } catch (Throwable) {
            return null;
        }
    }
}
