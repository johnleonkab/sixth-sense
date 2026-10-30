<?php

namespace App\Voice\Tools\Agenda;

use App\Models\User;
use App\Scheduling\AgendaTaskStatus;
use App\Scheduling\Scheduler;
use App\Voice\OpenUi\Views\AgendaTaskView;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use Throwable;

/**
 * Undo what the scheduler booked (or withdraw what it proposed).
 */
class CancelScheduledTask implements VoiceTool
{
    public function __construct(private Scheduler $scheduler) {}

    public function name(): string
    {
        return 'cancel_scheduled_task';
    }

    public function description(): string
    {
        return 'Deshace lo que agendó el agendador: quita la tarea del calendario o retira la reunión propuesta. Úsala cuando diga "quítalo", "no lo agendes", "deshaz eso".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['task_id' => ['type' => 'integer', 'description' => 'El task_id de schedule_task.']],
            'required' => ['task_id'],
        ];
    }

    public function rules(): array
    {
        return ['task_id' => ['required', 'integer']];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $task = $user->agendaTasks()->find($arguments['task_id']);

        if ($task === null) {
            return ToolResult::error('No encuentro esa tarea agendada.');
        }

        if (! in_array($task->status, [AgendaTaskStatus::Scheduled, AgendaTaskStatus::Proposed, AgendaTaskStatus::Conflict], true)) {
            return new ToolResult(['status' => $task->status->label(), 'say' => 'No había nada que quitar.']);
        }

        try {
            $task = $this->scheduler->cancel($task);
        } catch (Throwable $exception) {
            report($exception);

            return ToolResult::error('Google Calendar no me deja quitarlo ahora. Puede borrarlo desde el calendario.');
        }

        return new ToolResult(
            output: ['status' => $task->status->label(), 'say' => "Quitado: «{$task->title}» ya no está en tu calendario."],
            canvas: AgendaTaskView::canvas($user, $task, $context->timezone()),
        );
    }
}
