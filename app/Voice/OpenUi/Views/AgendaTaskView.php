<?php

namespace App\Voice\OpenUi\Views;

use App\Models\AgendaTask;
use App\Models\User;
use App\Scheduling\AgendaTaskStatus;
use App\Voice\OpenUi\Ref;
use App\Voice\OpenUi\ViewBuilder;
use App\Voice\Tools\Canvas;

/**
 * The scheduler's answer about one task: where it went (or why it could
 * not), how urgent it judged it and why, and how to undo it. Nothing reaches
 * the calendar before the "sí": a meeting invites no one, a task books no time.
 */
class AgendaTaskView
{
    public static function canvas(User $user, AgendaTask $task, string $timezone): Canvas
    {
        $view = new ViewBuilder($task->title);

        $view->add('Chips', [array_values(array_filter([
            $task->status->label(),
            $task->urgency?->label(),
            $task->duration_minutes ? "{$task->duration_minutes} min" : null,
        ]))]);

        if ($task->starts_at && $task->ends_at) {
            $start = $task->starts_at->setTimezone($timezone)->locale('es');
            $view->add('Text', [ucfirst($start->isoFormat('dddd D [de] MMMM · HH:mm')).'–'.$task->ends_at->setTimezone($timezone)->format('H:i')]);
        }

        if (filled($task->urgency_reason)) {
            $view->add('Text', [$task->urgency_reason, 'muted']);
        }

        $people = array_values(array_filter(array_map(fn (array $person): ?string => $person['name'] ?? $person['email'] ?? null, $task->attendees ?? [])));

        if ($people !== []) {
            $view->add('Text', ['Con '.implode(', ', $people), 'muted']);
        }

        if (filled($task->error)) {
            $view->add('Text', [$task->error, 'warn']);
        }

        $buttons = [];

        if ($task->event_url) {
            $buttons[] = $view->add('Button', ['Abrir en Calendar', new Ref('@OpenUrl('.json_encode($task->event_url, JSON_UNESCAPED_SLASHES).')')], top: false);
        }

        if ($task->status === AgendaTaskStatus::Scheduled) {
            $buttons[] = $view->add('Button', ['Quitar del calendario', new Ref("@Do(\"task-cancel:{$task->id}\")"), 'tertiary'], top: false);
        }

        if ($buttons !== []) {
            $view->add('Buttons', [$buttons]);
        }

        if ($task->status === AgendaTaskStatus::Proposed && $task->pendingAction) {
            $task->kind === 'meeting'
                ? $view->proposal($task->pendingAction, 'convocar', 'Reunión convocada')
                : $view->proposal($task->pendingAction, 'reservar', 'En tu calendario');
        }

        return $view->canvas($user, 'Agendador', 'focus');
    }
}
