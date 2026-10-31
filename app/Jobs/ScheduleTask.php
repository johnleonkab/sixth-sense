<?php

namespace App\Jobs;

use App\Alerts\AlertFollowUp;
use App\Models\AgendaTask;
use App\Scheduling\AgendaTaskStatus;
use App\Scheduling\Scheduler;
use App\Scheduling\SchedulingRequest;
use App\Scheduling\Urgency;
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
        public ?string $urgency = null,
        public bool $call = false,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->task->id;
    }

    public function handle(Scheduler $scheduler, AlertFollowUp $followUp): void
    {
        $task = $this->task->fresh();

        if ($task === null || $task->status !== AgendaTaskStatus::Assessing) {
            return;
        }

        $task = $scheduler->schedule($task, new SchedulingRequest($task->title, $task->context, $this->effortMinutes, $this->deadline, $this->people, Urgency::tryFrom((string) $this->urgency), $this->call));

        // A call asked for in a message: the answer to it says when, or asks.
        if ($this->call && $task->inboxItem !== null) {
            $followUp->answerCall($task);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->task->fresh()?->update(['status' => AgendaTaskStatus::Failed, 'error' => 'No he podido agendarlo. Inténtalo de nuevo por voz.']);
    }
}
