<?php

namespace App\Scheduling;

use App\Actions\Actions;
use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\Triage;
use App\Connectors\Calendar\CalendarWriter;
use App\Connectors\Calendar\NewEvent;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\RecipientNotResolved;
use App\Connectors\Mail\RecipientResolver;
use App\Events\InboxItemCreated;
use App\Events\InboxItemUpdated;
use App\Models\AgendaTask;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The scheduler agent: for something the person has to do, it decides how
 * urgent it is and how long it takes (TaskAssessor), finds a slot in their
 * calendars (SlotFinder) and proposes it there. Nothing reaches the calendar
 * without the person's "sí", neither their own work nor a meeting with others;
 * when nothing fits before it is due, they get an alert.
 */
class Scheduler
{
    public function __construct(
        private TaskAssessor $assessor,
        private SlotFinder $slots,
        private CalendarWriter $calendar,
        private RecipientResolver $recipients,
        private Actions $actions,
    ) {}

    public function schedule(AgendaTask $task, SchedulingRequest $request): AgendaTask
    {
        $user = $task->user;
        $accounts = $this->calendar->accounts($user);

        if ($accounts === []) {
            return $this->finish($task, ['status' => AgendaTaskStatus::Failed, 'error' => 'Ninguna cuenta puede gestionar el calendario. Actívalo en Configuración (Conexiones).']);
        }

        $now = CarbonImmutable::now(config('agenda.timezone'));
        $assessment = $this->assess($task, $request, $now);

        try {
            $people = $assessment->kind === 'meeting' ? $this->recipients->resolveAll($user, $assessment->people) : [];
        } catch (RecipientNotResolved $exception) {
            return $this->finish($task, [...$this->decided($assessment), 'status' => AgendaTaskStatus::Failed, 'error' => $exception->getMessage()]);
        }

        $from = $assessment->urgency->earliestStart($now)->max($now->addMinutes((int) config('agenda.lead_minutes')));
        $until = $assessment->urgency->latestEnd($now);

        if ($assessment->deadline !== null) {
            $until = $until->min($assessment->deadline->setTimezone($now->getTimezone())->endOfDay());
        }

        $slot = $until->greaterThan($from)
            ? $this->slots->find($user, $from, $until, $assessment->durationMinutes, array_map(fn (MailAddress $person): string => (string) $person->email, $people))
            : null;

        $decided = [...$this->decided($assessment), 'attendees' => array_map(fn (MailAddress $person): array => $person->toArray(), $people)];

        if ($slot === null) {
            $task = $this->finish($task, [...$decided, 'status' => AgendaTaskStatus::Conflict]);
            $this->alertConflict($task, $until);

            return $task;
        }

        [$start, $end] = $slot;
        $squeeze = $slot[2] ?? [];
        $event = new NewEvent(
            accountId: $accounts[0]->accountId,
            title: $assessment->title,
            start: $start,
            end: $end,
            attendees: $people,
            description: trim(($assessment->description ?? $task->context ?? '')."\n\nAgendado por sixth-sense · {$assessment->urgency->label()}: {$assessment->reason}"),
            videoCall: $people !== [],
            task: $people === [],
        );

        // Their own work or a meeting with others: both wait for the person's
        // "sí". Once confirmed, the task is marked as scheduled with its event.
        $label = $people !== [] ? "Convocar «{$event->title}»" : "Reservar «{$event->title}» en tu calendario";
        $action = $this->actions->propose($user, 'calendar.change', $label, array_filter(['kind' => 'create', 'event' => $event->toArray(), 'squeeze' => $squeeze, 'agenda_task_id' => $task->id, 'inbox_item_id' => $task->inbox_item_id], fn (mixed $value): bool => $value !== null));

        // Stored in UTC: the database keeps no timezone.
        return $this->finish($task, [...$decided, 'status' => AgendaTaskStatus::Proposed, 'starts_at' => $start->utc(), 'ends_at' => $end->utc(), 'pending_action_id' => $action->id]);
    }

    /**
     * Take it off the calendar (or withdraw the proposal).
     */
    public function cancel(AgendaTask $task): AgendaTask
    {
        if ($task->status === AgendaTaskStatus::Scheduled && $task->event_ref) {
            $this->calendar->cancel($task->user, $task->event_ref);
        }

        if ($task->status === AgendaTaskStatus::Proposed && $task->pendingAction) {
            $this->actions->cancel($task->pendingAction);
        }

        return $this->finish($task, ['status' => AgendaTaskStatus::Cancelled]);
    }

    /**
     * The assessor's judgement; safe defaults when it can not answer.
     */
    private function assess(AgendaTask $task, SchedulingRequest $request, CarbonImmutable $now): TaskAssessment
    {
        try {
            $workContext = $task->user->workContext?->usable() ? $task->user->workContext->summary : null;

            return $this->assessor->assess($task->user, $request, $now, $workContext, $this->slots->load($task->user, $now));
        } catch (Throwable $exception) {
            report($exception);

            return new TaskAssessment(
                title: $request->title,
                urgency: $request->urgency ?? Urgency::ThisWeek,
                reason: 'No he podido valorarlo con detalle: lo busco para esta semana.',
                durationMinutes: $request->effortMinutes ?? 30,
                kind: $request->people === [] ? 'task' : 'meeting',
                deadline: $request->deadline ? CarbonImmutable::parse($request->deadline, $now->getTimezone()) : null,
                people: $request->people,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decided(TaskAssessment $assessment): array
    {
        return [
            'title' => $assessment->title,
            'kind' => $assessment->kind,
            'urgency' => $assessment->urgency,
            'urgency_reason' => $assessment->reason,
            'duration_minutes' => $assessment->durationMinutes,
            'deadline' => $assessment->deadline?->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function finish(AgendaTask $task, array $changes): AgendaTask
    {
        $task->update($changes);

        // The alert it came from shows where its task stands.
        if ($task->inboxItem) {
            InboxItemUpdated::dispatch($task->inboxItem);
        }

        return $task;
    }

    /**
     * Nothing fits before it is due: the person decides.
     */
    private function alertConflict(AgendaTask $task, CarbonImmutable $until): void
    {
        $item = $task->user->inboxItems()->firstOrCreate(
            ['source' => AlertSource::Agenda, 'external_id' => "agenda:{$task->id}"],
            [
                'actor_name' => 'Agendador',
                'title' => "Sin hueco: {$task->title}",
                'body' => $task->context,
                'why' => "No encuentro {$task->duration_minutes} minutos libres para «{$task->title}» antes del ".$until->locale('es')->isoFormat('dddd D [de] MMMM').'. Decide qué mover.',
                'relevance' => 70,
                'status' => AlertStatus::Pending,
                'triage' => Triage::Important,
                'payload' => ['agenda_task_id' => $task->id],
                'received_at' => now(),
            ],
        );

        if ($item->wasRecentlyCreated) {
            InboxItemCreated::dispatch($item);
        }
    }
}
