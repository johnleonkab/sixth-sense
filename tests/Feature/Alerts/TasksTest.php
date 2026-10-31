<?php

use App\Alerts\Actions\ActionClassifier;
use App\Alerts\Actions\ClassifiedAction;
use App\Alerts\Actions\FakeActionClassifier;
use App\Alerts\AlertFollowUp;
use App\Alerts\AlertSource;
use App\Alerts\Commitments\Commitments;
use App\Alerts\Commitments\TaskDeliveries;
use App\Alerts\Replies\FakeReplyPlanner;
use App\Alerts\Replies\ReplyPlanner;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\CalendarWriter;
use App\Connectors\Calendar\FakeCalendarReader;
use App\Connectors\Calendar\FakeCalendarWriter;
use App\Connectors\Chat\ChatMessage;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\FoundMessage;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\MailMessage;
use App\Connectors\Mail\MailSearch;
use App\Connectors\Mail\MailSearchResult;
use App\Models\AlertSetting;
use App\Models\InboxItem;
use App\Models\User;
use App\Scheduling\AgendaTaskStatus;
use App\Scheduling\Assessors\FakeTaskAssessor;
use App\Scheduling\TaskAssessor;
use Carbon\CarbonImmutable;

/**
 * The sample calendar: every weekday, 9:30–9:45, 11:00–11:45, 12:00–12:30,
 * 13:00–14:00 and 16:30–17:15.
 */
beforeEach(function () {
    // Wednesday 14 October, 8:00 in Madrid.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 08:00', 'Europe/Madrid'));
    $this->app->instance(CalendarReader::class, new FakeCalendarReader);
    $this->app->instance(CalendarWriter::class, $this->writer = new FakeCalendarWriter);
    $this->app->instance(TaskAssessor::class, new FakeTaskAssessor);
    $this->app->instance(Mailbox::class, $this->mailbox = new class extends FakeMailbox
    {
        /** @var list<MailMessage> */
        public array $sent = [];

        public function search(User $user, MailSearch $search): MailSearchResult
        {
            return $search->folder === 'sent'
                ? new MailSearchResult(array_map(fn (MailMessage $message): FoundMessage => new FoundMessage($message), $this->sent), ['demo@sixth-sense.test'])
                : parent::search($user, $search);
        }
    });
    $this->app->instance(Chats::class, $this->chats = new class extends FakeChats
    {
        /** @var list<ChatMessage> */
        public array $mine = [];

        public function activity(User $user, CarbonImmutable $since, int $limit = 150): array
        {
            return $this->mine;
        }
    });
    $this->app->instance(ReplyPlanner::class, new FakeReplyPlanner);
    $this->app->instance(ActionClassifier::class, $this->classifier = new FakeActionClassifier);
    $this->user = User::factory()->create(['name' => 'John León']);
    AlertSetting::factory()->for($this->user)->create(['source' => AlertSource::Agenda, 'enabled' => true]);
});

function deliverable(string $deadline = '2026-10-16', string $origin = 'asked', int $minutes = 30): ClassifiedAction
{
    return new ClassifiedAction(ClassifiedAction::TASK, ClassifiedAction::SURE, 'Pasarle a Luis la user story del checkout', data: ['deliverable' => 'User story', 'expected_by' => 'Luis', 'deadline' => $deadline, 'origin' => $origin, 'duration_minutes' => $minutes]);
}

function myMessage(string $text, string $to = 'Luis Gómez'): ChatMessage
{
    return new ChatMessage('demo:D09:2001.000100', 'demo:D09', $to, 'John León', 'U_ME', $text, CarbonImmutable::now()->subMinutes(5), isMine: true, url: 'https://slack.test/D09');
}

test('work asked for in a message gets three slots before its deadline, with a day of margin, and the first is proposed', function () {
    $this->classifier->answer = [deliverable('2026-10-19')];
    $alert = InboxItem::factory()->for($this->user)->create(['source' => AlertSource::Gmail, 'external_id' => 'demo.1', 'actor_name' => 'Luis Gómez']);

    app(AlertFollowUp::class)->plan($alert);
    $task = $alert->refresh()->agendaTask;
    $starts = array_map(fn (array $option): string => CarbonImmutable::parse($option[0])->setTimezone('Europe/Madrid')->format('D H:i'), $task->options);

    expect($task->status)->toBe(AgendaTaskStatus::Proposed)
        ->and($starts)->toBe(['Wed 10:00', 'Thu 10:00', 'Fri 10:00'])
        ->and($task->starts_at->equalTo(CarbonImmutable::parse($task->options[0][0])))->toBeTrue()
        ->and($task->pendingAction->label)->toBe('Reservar «Pasarle a Luis la user story del checkout» en tu calendario');
});

test('another of the slots is one tap; another length looks again', function () {
    $this->classifier->answer = [deliverable('2026-10-19')];
    $alert = InboxItem::factory()->for($this->user)->create(['source' => AlertSource::Gmail, 'external_id' => 'demo.1']);
    app(AlertFollowUp::class)->plan($alert);
    $settle = fn (array $arguments) => $this->actingAs($this->user)->postJson(route('voice.tools.run', 'settle_alert_action'), ['arguments' => ['alert_id' => $alert->id, 'action' => 'a0', ...$arguments]])->assertOk();

    $settle(['slot' => 2])->assertJsonPath('output.say', 'Vale, lo cambio a ese hueco.');
    $task = $alert->refresh()->agendaTask;

    expect($task->starts_at->setTimezone('Europe/Madrid')->format('D H:i'))->toBe('Fri 10:00')
        ->and(CarbonImmutable::parse($task->pendingAction->payload['event']['start'])->format('D H:i'))->toBe('Fri 10:00');

    $settle(['duration_minutes' => 90]);

    expect($task->refresh()->status)->toBe(AgendaTaskStatus::Cancelled)
        ->and($alert->refresh()->agendaTask->duration_minutes)->toBe(90)
        ->and($alert->agendaTask->status)->toBe(AgendaTaskStatus::Proposed);
});

test('one yes books the task and sends the answer', function () {
    $this->classifier->answer = [deliverable()];
    $alert = InboxItem::factory()->for($this->user)->create(['source' => AlertSource::Slack, 'external_id' => 'demo:C01:1001.000400', 'actor_name' => 'Luis Gómez']);
    app(AlertFollowUp::class)->plan($alert);

    $this->actingAs($this->user)->postJson(route('alerts.reply', $alert))->assertOk()->assertJsonCount(2, 'actions');

    expect($this->writer->changes)->toHaveCount(1)
        ->and($this->writer->changes[0][1]['task'])->toBeTrue()
        ->and($this->chats->sent)->toHaveCount(1)
        ->and($alert->refresh()->agendaTask->status)->toBe(AgendaTaskStatus::Scheduled);
});

test('a promise of the person in Slack becomes an alert offering time for it, once', function () {
    $this->chats->mine = [myMessage('Te lo paso antes del jueves sin falta.'), myMessage('Vale, gracias.')];
    $this->classifier->answer = [deliverable(origin: 'committed')];

    $opened = app(Commitments::class)->check($this->user);

    expect($opened)->toHaveCount(1)
        ->and($opened[0]->source)->toBe(AlertSource::Agenda)
        ->and($opened[0]->why)->toBe('Le dijiste a Luis: «Te lo paso antes del jueves sin falta.». ¿Lo pongo en el calendario?')
        ->and($opened[0]->payload['commitment'])->toMatchArray(['source' => 'slack', 'to' => 'Luis Gómez'])
        ->and($opened[0]->agendaTask->status)->toBe(AgendaTaskStatus::Proposed)
        ->and($this->classifier->classified)->toHaveCount(1)
        ->and($this->classifier->classified[0]->text)->toContain('Lo escribe John León a Luis Gómez');

    expect(app(Commitments::class)->check($this->user))->toBe([]);
});

test('a promise in a sent email too; and nothing when the classifier sees no promise of their own', function (string $origin, int $alerts) {
    $this->mailbox->sent = [new MailMessage('demo.sent.1', 'demo@sixth-sense.test', new MailAddress('John León', 'john@cm.com'), [new MailAddress('Luis Gómez', 'luis@cm.com')], 'Checkout', CarbonImmutable::now()->subMinutes(3), 'Te mando la historia mañana.', body: 'Luis, te mando la historia mañana.')];
    $this->classifier->answer = [deliverable(origin: $origin)];

    expect(app(Commitments::class)->check($this->user))->toHaveCount($alerts);
})->with(['committed' => ['committed', 1], 'asked' => ['asked', 0]]);

test('the day before it is due, a task with no sign of delivery gets one follow-up', function (bool $delivered) {
    $alert = InboxItem::factory()->for($this->user)->create(['source' => AlertSource::Slack, 'external_id' => 'demo:C01:1001.000400', 'actor_name' => 'Luis Gómez']);
    $task = $this->user->agendaTasks()->create(['inbox_item_id' => $alert->id, 'origin' => 'alert', 'kind' => 'task', 'title' => 'Pasarle la historia a Luis', 'status' => AgendaTaskStatus::Scheduled, 'deadline' => '2026-10-15']);
    $this->chats->answered = $delivered ? ['demo:C01:1001.000400'] : [];

    $raised = app(TaskDeliveries::class)->check($this->user);

    expect($raised)->toHaveCount($delivered ? 0 : 1);

    if (! $delivered) {
        expect($raised[0]->why)->toBe('Mañana vence «Pasarle la historia a Luis» para Luis Gómez y no veo que se lo hayas pasado. ¿Vas bien?')
            ->and($raised[0]->payload['agenda_task_id'])->toBe($task->id)
            ->and(app(TaskDeliveries::class)->check($this->user))->toBe([]);
    }
})->with(['not delivered' => false, 'delivered' => true]);

test('the commitments check is scheduled for people who let the scheduler raise alerts', function () {
    User::factory()->create();

    $this->artisan('alerts:check-commitments')->expectsOutputToContain('1 usuarios en cola.')->assertSuccessful();
});

test('a task that does not meet the three conditions never reaches the scheduler', function () {
    $this->classifier->answer = [new ClassifiedAction(ClassifiedAction::TASK, ClassifiedAction::DOUBT, 'Echarle un ojo al hilo', 'Parece una tarea para ti, pero no estoy seguro: ¿lo es?')];
    $alert = InboxItem::factory()->for($this->user)->create(['source' => AlertSource::Gmail, 'external_id' => 'demo.1']);

    app(AlertFollowUp::class)->plan($alert);

    expect($alert->refresh()->agendaTask)->toBeNull();
});
