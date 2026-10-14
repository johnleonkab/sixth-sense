<?php

namespace App\Actions\Handlers;

use App\Actions\ActionHandler;
use App\Models\PendingAction;
use App\Scheduling\Scheduler;
use App\Scheduling\SchedulingRequest;

/**
 * Hands a task of the person's own to the scheduler, which finds it time in
 * their calendar (or proposes a meeting when others take part).
 */
class ScheduleAgendaTask implements ActionHandler
{
    public function __construct(private Scheduler $scheduler) {}

    public function execute(PendingAction $action): array
    {
        $payload = $action->payload;
        $task = $action->user->agendaTasks()->create(['origin' => $payload['origin'] ?? 'wingman', 'title' => $payload['title'], 'context' => $payload['context'] ?? null]);

        $task = $this->scheduler->schedule($task, new SchedulingRequest(
            title: $payload['title'],
            context: $payload['context'] ?? null,
            effortMinutes: $payload['effort_minutes'] ?? null,
            deadline: $payload['deadline'] ?? null,
        ));

        return array_filter([
            'task_id' => $task->id,
            'status' => $task->status->label(),
            'starts_at' => $task->starts_at?->toIso8601String(),
            'url' => $task->event_url,
        ], fn (mixed $value): bool => $value !== null);
    }
}
