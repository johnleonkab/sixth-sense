<?php

use App\Alerts\Actions\ActionClassifier;
use App\Alerts\Actions\ClassifiedAction;
use App\Alerts\Actions\FakeActionClassifier;
use App\Alerts\AlertFollowUp;
use App\Alerts\AlertSource;
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
use App\Models\AlertSetting;
use App\Models\InboxItem;
use App\Models\User;
use App\Scheduling\AgendaTaskStatus;
use App\Scheduling\Assessors\FakeTaskAssessor;
use App\Scheduling\TaskAssessor;
use App\Scheduling\Urgency;
use Carbon\CarbonImmutable;

/**
 * The sample calendar: every weekday, 9:30–9:45, 11:00–11:45, 12:00–12:30,
 * 13:00–14:00 and 16:30–17:15. Marta's address is marta@covermanager.test.
 */
beforeEach(function () {
    // Wednesday 14 October, 8:00 in Madrid.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 08:00', 'Europe/Madrid'));
    $this->app->instance(CalendarReader::class, $this->reader = new FakeCalendarReader);
    $this->app->instance(CalendarWriter::class, $this->writer = new FakeCalendarWriter);
    $this->app->instance(TaskAssessor::class, new FakeTaskAssessor);
    $this->app->instance(Mailbox::class, new FakeMailbox);
    $this->app->instance(Chats::class, $this->chats = new FakeChats);
    $this->app->instance(ReplyPlanner::class, $this->planner = new FakeReplyPlanner);
    $this->app->instance(ActionClassifier::class, $this->classifier = new FakeActionClassifier);
    $this->user = User::factory()->create(['name' => 'John León']);
    AlertSetting::factory()->for($this->user)->create(['source' => AlertSource::Agenda, 'enabled' => true]);
    $this->alert = InboxItem::factory()->for($this->user)->create([
        'source' => AlertSource::Slack,
        'external_id' => 'demo:C01:1001.000400',
        'actor_name' => 'Marta Ruiz',
        'title' => 'Prepago',
    ]);
});

function askForCall(array $data, string $confidence = ClassifiedAction::SURE): InboxItem
{
    test()->classifier->answer = [new ClassifiedAction(ClassifiedAction::CALL, $confidence, 'Marta quiere una llamada.', $confidence === ClassifiedAction::DOUBT ? 'No sé si te incluye.' : null, $data)];
    app(AlertFollowUp::class)->plan(test()->alert);

    return test()->alert->refresh();
}

function lastFindings(): string
{
    return collect(test()->planner->planned)->last()->findings;
}

test('a call is looked for sooner than own work, and the window is widened once', function () {
    $now = CarbonImmutable::parse('2026-10-14 08:00', 'Europe/Madrid');

    expect(Urgency::Now->callUntil($now)->format('D H:i'))->toBe('Wed 23:59')
        ->and(Urgency::Today->callUntil($now)->format('D H:i'))->toBe('Thu 08:00')
        ->and(Urgency::ThisWeek->callUntil($now)->format('D d'))->toBe('Wed 21')
        ->and(Urgency::Now->wider())->toBe(Urgency::Today)
        ->and(Urgency::Today->wider())->toBe(Urgency::ThisWeek)
        ->and(Urgency::Whenever->wider())->toBe(Urgency::Whenever);
});

test('with a slot, the event and the answer are ready, and one yes books it and sends it', function () {
    $this->reader->othersBusy = ['marta@covermanager.test' => []];

    $alert = askForCall(['people' => ['Marta'], 'people_sure' => true, 'duration_minutes' => 30, 'urgency' => 'today']);
    $task = $alert->agendaTask;

    expect($task->status)->toBe(AgendaTaskStatus::Proposed)
        ->and($task->kind)->toBe('meeting')
        ->and($task->starts_at->setTimezone('Europe/Madrid')->format('D H:i'))->toBe('Wed 10:00')
        ->and(lastFindings())->toContain('Llamada preparada: el miércoles 14 de octubre, 10:00 (30 min) con Marta')
        ->and($alert->proposal['reply']['text'])->toBe('Hola Marta, lo miro hoy y te digo algo.');

    $this->actingAs($this->user)->postJson(route('alerts.reply', $alert))->assertOk()->assertJsonCount(2, 'actions');

    expect($this->writer->changes)->toHaveCount(1)
        ->and($this->writer->changes[0][1]['attendees'][0]['email'])->toBe('marta@covermanager.test')
        ->and($this->chats->sent)->toHaveCount(1)
        ->and($task->refresh()->status)->toBe(AgendaTaskStatus::Scheduled);
});

test('with no slot in its window, it looks once further', function () {
    $this->reader->othersBusy = ['marta@covermanager.test' => [[CarbonImmutable::parse('2026-10-14 08:00', 'Europe/Madrid'), CarbonImmutable::parse('2026-10-15 23:59', 'Europe/Madrid')]]];

    $task = askForCall(['people' => ['Marta'], 'people_sure' => true, 'duration_minutes' => 30, 'urgency' => 'today'])->agendaTask;

    expect($task->status)->toBe(AgendaTaskStatus::Proposed)
        ->and($task->starts_at->setTimezone('Europe/Madrid')->format('D H:i'))->toBe('Fri 10:00')
        ->and($task->urgency_reason)->toContain('No había hueco: lo busco hasta Esta semana.');
});

test('with nothing even further, the answer says so and asks, and no other alert is raised', function () {
    $this->reader->othersBusy = ['marta@covermanager.test' => [[CarbonImmutable::parse('2026-10-14 08:00', 'Europe/Madrid'), CarbonImmutable::parse('2026-11-30 23:59', 'Europe/Madrid')]]];

    $task = askForCall(['people' => ['Marta'], 'people_sure' => true, 'duration_minutes' => 30, 'urgency' => 'today'])->agendaTask;

    expect($task->status)->toBe(AgendaTaskStatus::Conflict)
        ->and(lastFindings())->toContain('No hay hueco para la llamada')->toContain('más flexibles')
        ->and($this->user->inboxItems()->where('source', AlertSource::Agenda)->exists())->toBeFalse();
});

test('someone whose calendar can not be seen is offered a few slots, one per day, and nothing is booked', function () {
    $task = askForCall(['people' => ['Marta'], 'people_sure' => true, 'duration_minutes' => 30, 'urgency' => 'this_week'])->agendaTask;

    expect($task->status)->toBe(AgendaTaskStatus::Offered)
        ->and($task->pending_action_id)->toBeNull()
        ->and(array_map(fn (array $option): string => CarbonImmutable::parse($option[0])->setTimezone('Europe/Madrid')->format('D H:i'), $task->options))->toBe(['Wed 10:00', 'Thu 10:00', 'Fri 10:00'])
        ->and(lastFindings())->toContain('alguien de fuera')->toContain('miércoles 14 de octubre, 10:00; jueves 15 de octubre, 10:00');

    $this->actingAs($this->user)->postJson(route('alerts.reply', $this->alert))->assertOk()->assertJsonCount(1, 'actions');
});

test('when it is not sure who goes, the people in the conversation are ticked and usual colleagues suggested', function () {
    $alert = askForCall(['people' => [], 'duration_minutes' => 30]);
    $call = $alert->proposal['actions'][0];

    expect($call['data']['needs'])->toBe(['people'])
        ->and(collect($call['data']['candidates'])->where('checked', true)->pluck('name')->all())->toContain('Marta Ruiz')
        ->and(collect($call['data']['candidates'])->where('checked', false)->isNotEmpty())->toBeTrue()
        ->and($alert->agendaTask)->toBeNull();

    $this->reader->othersBusy = ['marta@covermanager.test' => []];
    $this->actingAs($this->user)->postJson(route('voice.tools.run', 'settle_alert_action'), ['arguments' => ['alert_id' => $alert->id, 'action' => 'a0', 'people' => ['Marta']]])
        ->assertOk()->assertJsonPath('output.say', 'Vale, busco hueco otra vez.');

    expect($alert->refresh()->proposal['actions'][0]['data'])->not->toHaveKey('needs')
        ->and($alert->agendaTask->status)->toBe(AgendaTaskStatus::Proposed);
});

test('the length: inferred between 15 and 60 it is used; otherwise the usual one after three the same; otherwise it asks', function (?int $inferred, array $usual, ?int $expected) {
    foreach ($usual as $minutes) {
        $this->user->agendaTasks()->create(['origin' => 'alert', 'title' => 'Llamada con Marta', 'kind' => 'meeting', 'status' => AgendaTaskStatus::Scheduled, 'duration_minutes' => $minutes, 'attendees' => [['name' => 'Marta Ruiz', 'email' => 'marta@covermanager.test']]]);
    }

    $data = askForCall(['people' => ['Marta'], 'people_sure' => true, 'duration_minutes' => $inferred])->proposal['actions'][0]['data'];

    expect($data['duration_minutes'] ?? null)->toBe($expected)
        ->and($data['needs'] ?? [])->toBe($expected === null ? ['duration'] : []);
})->with([
    'inferred' => [45, [], 45],
    'too long to trust' => [90, [], null],
    'unknown, nothing learned' => [null, [], null],
    'unknown, three the same' => [null, [45, 45, 45], 45],
    'unknown, changed last time' => [null, [45, 45, 30], null],
]);

test('changing who goes or how long once proposed withdraws the slot and looks again', function () {
    $this->reader->othersBusy = ['marta@covermanager.test' => [], 'lucia@covermanager.test' => []];
    $alert = askForCall(['people' => ['Marta'], 'people_sure' => true, 'duration_minutes' => 30]);
    $first = $alert->agendaTask;

    $this->actingAs($this->user)->postJson(route('voice.tools.run', 'settle_alert_action'), ['arguments' => ['alert_id' => $alert->id, 'action' => 'a0', 'duration_minutes' => 60]])->assertOk();

    expect($first->refresh()->status)->toBe(AgendaTaskStatus::Cancelled)
        ->and($first->pendingAction->status->value)->toBe('cancelled')
        ->and($alert->refresh()->agendaTask->duration_minutes)->toBe(60)
        ->and($alert->agendaTask->status)->toBe(AgendaTaskStatus::Proposed);
});

test('a call in doubt prepares nothing until the yes', function () {
    $alert = askForCall(['people' => ['Marta'], 'people_sure' => true, 'duration_minutes' => 30], ClassifiedAction::DOUBT);

    expect($alert->agendaTask)->toBeNull()
        ->and($alert->proposal['actions'][0]['doubt'])->toBe('No sé si te incluye.');
});
