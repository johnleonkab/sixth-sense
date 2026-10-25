<?php

use App\Alerts\AlertFollowUp;
use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\Replies\FakeReplyPlanner;
use App\Alerts\Replies\ReplyPlanner;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\CalendarWriter;
use App\Connectors\Calendar\FakeCalendarReader;
use App\Connectors\Calendar\FakeCalendarWriter;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\Mailbox;
use App\Models\AgendaTask;
use App\Models\AlertSetting;
use App\Models\InboxItem;
use App\Models\User;
use App\Scheduling\AgendaTaskStatus;
use App\Scheduling\Assessors\FakeTaskAssessor;
use App\Scheduling\Scheduler;
use App\Scheduling\SchedulingRequest;
use App\Scheduling\SlotFinder;
use App\Scheduling\TaskAssessment;
use App\Scheduling\TaskAssessor;
use App\Scheduling\Urgency;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/**
 * The sample calendar: every weekday, 9:30–9:45, 11:00–11:45, 12:00–12:30,
 * 13:00–14:00 and 16:30–17:15. Lunch is 14:00–15:00; meetings keep 10 minutes around them.
 */
beforeEach(function () {
    // Wednesday 14 October, 8:00 in Madrid.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 08:00', 'Europe/Madrid'));
    $this->app->instance(CalendarReader::class, $this->reader = new FakeCalendarReader);
    $this->app->instance(CalendarWriter::class, $this->writer = new FakeCalendarWriter);
    $this->app->instance(TaskAssessor::class, $this->assessor = new FakeTaskAssessor);
    $this->app->instance(Mailbox::class, new FakeMailbox);
    $this->app->instance(Chats::class, new FakeChats);
    $this->user = User::factory()->create(['name' => 'John León']);
});

function slot(int $minutes, string $from, string $to, array $others = []): ?array
{
    $found = app(SlotFinder::class)->find(test()->user, CarbonImmutable::parse($from, 'Europe/Madrid'), CarbonImmutable::parse($to, 'Europe/Madrid'), $minutes, $others);

    return $found ? [$found[0]->format('D H:i'), $found[1]->format('H:i')] : null;
}

function scheduleFor(string $title, array $request = []): AgendaTask
{
    $task = test()->user->agendaTasks()->create(['origin' => 'voice', 'title' => $title]);

    return app(Scheduler::class)->schedule($task, new SchedulingRequest($title, ...$request));
}

test('the first slot keeps clear of meetings, their margin and lunch', function (int $minutes, string $from, array $expected) {
    expect(slot($minutes, $from, '2026-10-16 23:59'))->toBe($expected);
})->with([
    'half an hour first thing: after the daily' => [30, '2026-10-14 08:30', ['Wed 10:00', '10:30']],
    'an hour: nothing fits in the morning, and lunch is kept' => [60, '2026-10-14 08:30', ['Wed 15:00', '16:00']],
    'from midday: after lunch' => [45, '2026-10-14 12:00', ['Wed 15:00', '15:45']],
]);

test('weekends are skipped, and others must be free too', function () {
    // From Saturday: Monday is the first working day.
    expect(slot(30, '2026-10-17 08:00', '2026-10-20 23:59'))->toBe(['Mon 10:00', '10:30']);

    $this->reader->othersBusy = ['marta@cm.com' => [[CarbonImmutable::parse('2026-10-19 09:55', 'Europe/Madrid'), CarbonImmutable::parse('2026-10-19 11:00', 'Europe/Madrid')]]];
    expect(slot(30, '2026-10-17 08:00', '2026-10-20 23:59', ['marta@cm.com']))->toBe(['Mon 15:00', '15:30']);
});

test('no slot when nothing fits before the end', function () {
    expect(slot(120, '2026-10-14 08:30', '2026-10-14 23:59'))->toBeNull();
});

test('the person own work is booked at once, marked as a task', function () {
    $task = scheduleFor('Revisar el PRD del prepago', ['context' => 'Marta lo pide antes del jueves.']);

    expect($task->status)->toBe(AgendaTaskStatus::Scheduled)
        ->and($task->urgency)->toBe(Urgency::ThisWeek)
        ->and($task->starts_at->setTimezone('Europe/Madrid')->format('D H:i'))->toBe('Wed 10:00')
        ->and($task->event_ref)->toBe('demo:primary:new-1')
        ->and($this->writer->changes[0][0])->toBe('create')
        ->and($this->writer->changes[0][1])->toMatchArray(['title' => 'Revisar el PRD del prepago', 'attendees' => [], 'task' => true, 'video_call' => false]);
});

test('urgency decides how far it may go: today only looks at today', function () {
    $task = scheduleFor('Contestar a riesgos', ['effortMinutes' => 120, 'urgency' => Urgency::Today]);

    expect($task->status)->toBe(AgendaTaskStatus::Conflict);

    $alert = $this->user->inboxItems()->sole();

    expect($alert->source)->toBe(AlertSource::Agenda)
        ->and($alert->status)->toBe(AlertStatus::Pending)
        ->and($alert->state())->toBe('important')
        ->and($alert->title)->toBe('Sin hueco: Contestar a riesgos')
        ->and($alert->why)->toContain('No encuentro 120 minutos libres');
});

test('a meeting with others is proposed and waits for the person', function () {
    $task = scheduleFor('Hablar del prepago', ['people' => ['Marta']]);

    expect($task->status)->toBe(AgendaTaskStatus::Proposed)
        ->and($task->kind)->toBe('meeting')
        ->and($task->attendees[0]['email'])->toBe('marta@covermanager.test')
        ->and($task->pendingAction->payload['event']['attendees'][0]['email'])->toBe('marta@covermanager.test')
        ->and($this->writer->changes)->toBe([]);
});

test('when the assessor can not answer, it is looked for this week with what was said', function () {
    $this->app->instance(TaskAssessor::class, new class implements TaskAssessor
    {
        public function assess(User $user, SchedulingRequest $request, CarbonImmutable $now, ?string $workContext, array $calendarLoad): TaskAssessment
        {
            throw new RuntimeException('Gemini is down');
        }
    });

    $task = scheduleFor('Preparar la demo', ['effortMinutes' => 45]);

    expect($task->status)->toBe(AgendaTaskStatus::Scheduled)
        ->and($task->duration_minutes)->toBe(45)
        ->and($task->urgency_reason)->toContain('No he podido valorarlo');
});

test('the assessor gets the person work context and how busy the coming days are', function () {
    $this->user->workContext()->create(['enabled' => true, 'status' => 'ready', 'summary' => 'Cerrando el lanzamiento del prepago.', 'generated_at' => now()]);

    scheduleFor('Revisar el PRD');

    expect($this->assessor->assessed[0]['work_context'])->toBe('Cerrando el lanzamiento del prepago.')
        ->and($this->assessor->assessed[0]['load'][0])->toBe('mié. 14 oct.: 5 reuniones, 3.3 h ocupadas');
});

test('what was booked can be taken off the calendar', function () {
    $task = app(Scheduler::class)->cancel(scheduleFor('Revisar el PRD'));

    expect($task->status)->toBe(AgendaTaskStatus::Cancelled)
        ->and($this->writer->changes[1])->toBe(['cancel', ['ref' => 'demo:primary:new-1']]);
});

test('an alert that asks for work of the person own gets time in the calendar, when the scheduler is on', function (bool $on) {
    $this->app->instance(ReplyPlanner::class, new FakeReplyPlanner);
    AlertSetting::factory()->for($this->user)->create(['source' => AlertSource::Agenda, 'enabled' => $on]);
    $item = InboxItem::factory()->for($this->user)->create(['source' => AlertSource::Gmail, 'external_id' => 'demo.1', 'actor_name' => 'Marta Ruiz']);

    app(AlertFollowUp::class)->plan($item);

    $task = AgendaTask::where('inbox_item_id', $item->id)->first();

    expect($task !== null)->toBe($on);

    if ($on) {
        expect($task->status)->toBe(AgendaTaskStatus::Scheduled)
            ->and($task->origin)->toBe('alert')
            ->and($task->title)->toBe('Revisar lo que pide Marta')
            ->and($item->refresh()->toAlert()['task']['status'])->toBe('scheduled');
    }
})->with(['on' => true, 'off' => false]);

function runAgendaTool(string $tool, array $arguments): TestResponse
{
    return test()->actingAs(test()->user)->postJson(route('voice.tools.run', $tool), ['arguments' => $arguments, 'timezone' => 'Europe/Madrid']);
}

test('by voice: it says when, and why that urgency, and it can be undone', function () {
    $booked = runAgendaTool('schedule_task', ['what' => 'Revisar el PRD del prepago', 'effort_minutes' => 30])
        ->assertJsonPath('canvas.view', 'agenda.task')
        ->assertJsonPath('canvas.props.task.status', 'scheduled')
        ->assertJsonPath('output.when', 'miércoles 14 de octubre, 10:00')
        ->assertJsonPath('output.urgency', 'Esta semana')
        ->assertJsonPath('output.say', 'Agendado: «Revisar el PRD del prepago», miércoles 14 de octubre, 10:00.')
        ->assertJsonPath('beside', null);

    runAgendaTool('cancel_scheduled_task', ['task_id' => $booked->json('output.task_id')])
        ->assertJsonPath('canvas.props.task.status', 'cancelled')
        ->assertJsonPath('output.say', 'Quitado: «Revisar el PRD del prepago» ya no está en tu calendario.');
});

test('when nothing fits, the day stays beside the task so the person can decide', function () {
    runAgendaTool('schedule_task', ['what' => 'Contestar a riesgos', 'effort_minutes' => 120, 'urgency' => 'today'])
        ->assertJsonPath('canvas.props.task.status', 'conflict')
        ->assertJsonPath('beside.view', 'calendar.day')
        ->assertJsonPath('beside.props.from', '2026-10-14');
});
