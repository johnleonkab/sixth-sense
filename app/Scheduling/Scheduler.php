<?php

namespace App\Scheduling;

use App\Actions\Actions;
use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\Triage;
use App\Connectors\Calendar\CalendarAccount;
use App\Connectors\Calendar\CalendarWriter;
use App\Connectors\Calendar\NewEvent;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\RecipientNotResolved;
use App\Connectors\Mail\RecipientResolver;
use App\Events\InboxItemCreated;
use App\Events\InboxItemUpdated;
use App\Models\AgendaTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
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

        $decided = [...$this->decided($assessment), 'attendees' => array_map(fn (MailAddress $person): array => $person->toArray(), $people)];

        if ($request->call && $people !== []) {
            return $this->call($task, $assessment, $people, $accounts, $now, $decided);
        }

        $from = $assessment->urgency->earliestStart($now)->max($now->addMinutes((int) config('agenda.lead_minutes')));
        $until = $assessment->urgency->latestEnd($now);

        if ($assessment->deadline !== null) {
            $until = $until->min($assessment->deadline->setTimezone($now->getTimezone())->endOfDay());
        }

        $slot = $until->greaterThan($from)
            ? $this->slots->find($user, $from, $until, $assessment->durationMinutes, array_map(fn (MailAddress $person): string => (string) $person->email, $people))
            : null;

        if ($slot === null) {
            $task = $this->finish($task, [...$decided, 'status' => AgendaTaskStatus::Conflict]);
            $this->alertConflict($task, $until);

            return $task;
        }

        return $this->propose($task, $assessment, $people, $accounts, $slot, $decided);
    }

    /**
     * A call someone asked the person for. It is looked for within a window
     * set by its urgency, widened once when nothing fits. With people whose
     * calendar can not be seen (outside the organisation), nothing is booked:
     * the reply offers them a few slots free for everyone else. With no slot,
     * the reply says so and asks when they are more flexible: no other alert.
     *
     * @param  list<MailAddress>  $people
     * @param  list<CalendarAccount>  $accounts
     * @param  array<string, mixed>  $decided
     */
    private function call(AgendaTask $task, TaskAssessment $assessment, array $people, array $accounts, CarbonImmutable $now, array $decided): AgendaTask
    {
        $user = $task->user;
        $from = $assessment->urgency->earliestStart($now)->max($now->addMinutes((int) config('agenda.lead_minutes')));
        $window = fn (Urgency $urgency): CarbonImmutable => $assessment->deadline !== null
            ? $urgency->callUntil($now)->min($assessment->deadline->setTimezone($now->getTimezone())->endOfDay())
            : $urgency->callUntil($now);
        $wider = $assessment->urgency->wider();
        $emails = array_map(fn (MailAddress $person): string => (string) $person->email, $people);
        $visible = $this->slots->visible($user, $emails, $from, $window($wider));
        $ours = array_map(fn (CalendarAccount $account): string => Str::after($account->email, '@'), $accounts);
        $outside = array_values(array_filter($emails, fn (string $email): bool => ! in_array($email, $visible, true) && ! in_array(Str::after($email, '@'), $ours, true)));
        $inside = array_values(array_diff($emails, $outside));
        $widened = ['urgency_reason' => trim("{$assessment->reason} No había hueco: lo busco hasta {$wider->label()}.")];

        if ($outside !== []) {
            $options = $this->slots->options($user, $from, $window($assessment->urgency), $assessment->durationMinutes, $inside);

            if ($options === []) {
                $options = $this->slots->options($user, $from, $window($wider), $assessment->durationMinutes, $inside);
                $decided = [...$decided, ...$widened];
            }

            return $this->finish($task, $options === []
                ? [...$decided, 'status' => AgendaTaskStatus::Conflict]
                : [...$decided, 'status' => AgendaTaskStatus::Offered, 'options' => array_map(fn (array $option): array => [$option[0]->utc()->toIso8601String(), $option[1]->utc()->toIso8601String()], $options)]);
        }

        $slot = $this->slots->find($user, $from, $window($assessment->urgency), $assessment->durationMinutes, $emails);

        if ($slot === null && $window($wider)->greaterThan($window($assessment->urgency))) {
            $slot = $this->slots->find($user, $from, $window($wider), $assessment->durationMinutes, $emails);
            $decided = [...$decided, ...$widened];
        }

        return $slot === null
            ? $this->finish($task, [...$decided, 'status' => AgendaTaskStatus::Conflict])
            : $this->propose($task, $assessment, $people, $accounts, $slot, $decided);
    }

    /**
     * Their own work or a meeting with others: both wait for the person's
     * "sí". Once confirmed, the task is marked as scheduled with its event.
     *
     * @param  list<MailAddress>  $people
     * @param  list<CalendarAccount>  $accounts
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable, 2?: list<array{ref: string, start: string, end: string}>}  $slot
     * @param  array<string, mixed>  $decided
     */
    private function propose(AgendaTask $task, TaskAssessment $assessment, array $people, array $accounts, array $slot, array $decided): AgendaTask
    {
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

        $label = $people !== [] ? "Convocar «{$event->title}»" : "Reservar «{$event->title}» en tu calendario";
        $action = $this->actions->propose($task->user, 'calendar.change', $label, array_filter(['kind' => 'create', 'event' => $event->toArray(), 'squeeze' => $squeeze, 'agenda_task_id' => $task->id, 'inbox_item_id' => $task->inbox_item_id], fn (mixed $value): bool => $value !== null));

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
