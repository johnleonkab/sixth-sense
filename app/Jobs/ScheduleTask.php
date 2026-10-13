<?php

namespace App\Jobs;

use App\Models\AgendaTask;
use App\Scheduling\AgendaTaskStatus;
use App\Scheduling\Scheduler;
use App\Scheduling\SchedulingRequest;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Finds time in the background for something an alert asks the person to do.
 */
class ScheduleTask implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 120;

    /**
     * @param  list<string>  $people
     */
    public function __construct(
        public AgendaTask $task,
        public ?int $effortMinutes = null,
        public ?string $deadline = null,
        public array $people = [],
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->task->id;
    }

    public function handle(Scheduler $scheduler): void
    {
        $task = $this->task->fresh();

        if ($task === null || $task->status !== AgendaTaskStatus::Assessing) {
            return;
        }

        $scheduler->schedule($task, new SchedulingRequest($task->title, $task->context, $this->effortMinutes, $this->deadline, $this->people));
    }

    public function failed(?Throwable $exception): void
    {
        $this->task->fresh()?->update(['status' => AgendaTaskStatus::Failed, 'error' => 'No he podido agendarlo. Inténtalo de nuevo por voz.']);
    }
}
