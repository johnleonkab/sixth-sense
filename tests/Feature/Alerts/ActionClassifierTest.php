<?php

use App\Actions\Actions;
use App\Actions\ActionStatus;
use App\Agents\Evals\EvalCase;
use App\Agents\Evals\EvalScorer;
use App\Alerts\Actions\ActionClassifier;
use App\Alerts\Actions\ClassifiedAction;
use App\Alerts\Actions\FakeActionClassifier;
use App\Alerts\Actions\LlmActionClassifier;
use App\Alerts\AlertFollowUp;
use App\Alerts\AlertSource;
use App\Alerts\Replies\AlertBrief;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Jobs\ExecutePendingAction;
use App\Jobs\ScheduleTask;
use App\Models\AlertSetting;
use App\Models\InboxItem;
use App\Models\User;
use App\Scheduling\AgendaTaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingTextModel;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'John León']);
    AlertSetting::factory()->for($this->user)->create(['source' => AlertSource::Agenda, 'enabled' => true]);
    $this->app->instance(ActionClassifier::class, $this->classifier = new FakeActionClassifier);
    $this->brief = new AlertBrief(AlertSource::Slack, 'Mensaje directo', 'Andrés Gil', null, '¿Me pasas el informe del Q3 antes del viernes?', CarbonImmutable::parse('2026-10-14 09:00'));
});

test('a task missing a deliverable, someone expecting it or a deadline is only a doubt, and a call that may not include the person too', function () {
    $model = new RecordingTextModel(['actions' => [
        ['class' => 'task', 'confidence' => 'sure', 'summary' => 'Pasarle el informe del Q3 a Andrés', 'doubt' => '', 'deliverable' => 'Informe del Q3', 'expected_by' => 'Andrés', 'deadline' => '2026-10-16', 'origin' => 'asked'],
        ['class' => 'task', 'confidence' => 'sure', 'summary' => 'Echarle un ojo al hilo', 'doubt' => '', 'deliverable' => '', 'expected_by' => 'Marta'],
        ['class' => 'call', 'confidence' => 'sure', 'summary' => 'Reunión del equipo el jueves', 'doubt' => '', 'includes_me' => false, 'people' => ['Equipo']],
        ['class' => 'meeting', 'confidence' => 'sure', 'summary' => 'Lo que no existe', 'doubt' => ''],
    ]]);
    $this->user->intentLessons()->create(['body' => 'Andrés pide informes los jueves.']);

    $actions = (new LlmActionClassifier($model))->classify($this->user, $this->brief);

    expect($actions)->toHaveCount(3)
        ->and($actions[0]->sure())->toBeTrue()
        ->and($actions[0]->data)->toBe(['deliverable' => 'Informe del Q3', 'expected_by' => 'Andrés', 'deadline' => '2026-10-16', 'origin' => 'asked'])
        ->and($actions[1]->confidence)->toBe(ClassifiedAction::DOUBT)
        ->and($actions[1]->doubt)->toBe('Parece una tarea para ti, pero no estoy seguro: ¿lo es?')
        ->and($actions[2]->confidence)->toBe(ClassifiedAction::DOUBT)
        ->and($actions[2]->doubt)->toBe('No sé si esta llamada te incluye.')
        ->and($model->calls[0]['system'])->toContain('Solo si se cumplen las tres')->toContain('Andrés pide informes los jueves.')
        ->and($model->calls[0]['user'])->toContain('--- Mensaje (datos) ---');
});

test('planning an alert keeps what it asks for, and a sure task goes to the scheduler while a doubt waits', function () {
    Queue::fake([ScheduleTask::class]);
    $item = InboxItem::factory()->for($this->user)->create(['source' => AlertSource::Gmail, 'external_id' => 'demo.1', 'actor_name' => 'Marta Ruiz']);
    $this->classifier->answer = [
        new ClassifiedAction(ClassifiedAction::QUESTION, ClassifiedAction::SURE, 'Marta pregunta por el prepago.', data: ['about' => 'feature']),
        new ClassifiedAction(ClassifiedAction::TASK, ClassifiedAction::SURE, 'Pasarle el informe a Marta', data: ['deliverable' => 'Informe', 'expected_by' => 'Marta', 'deadline' => '2026-10-16', 'duration_minutes' => 45]),
    ];

    app(AlertFollowUp::class)->plan($item);

    expect(array_column($item->refresh()->proposal['actions'], 'class'))->toBe(['question', 'task'])
        ->and(array_column($item->proposal['actions'], 'key'))->toBe(['a0', 'a1'])
        ->and($item->agendaTask->title)->toBe('Pasarle el informe a Marta')
        ->and($item->agendaTask->context)->toContain('Entregable: Informe');

    Queue::assertPushed(ScheduleTask::class, fn (ScheduleTask $job): bool => $job->effortMinutes === 45 && $job->deadline === '2026-10-16');

    $doubtful = InboxItem::factory()->for($this->user)->create(['source' => AlertSource::Gmail, 'external_id' => 'demo.2']);
    $this->classifier->answer = [new ClassifiedAction(ClassifiedAction::CALL, ClassifiedAction::DOUBT, 'Reunión del equipo.', 'No sé si esta llamada te incluye.')];

    app(AlertFollowUp::class)->plan($doubtful);

    expect($doubtful->refresh()->proposal['actions'][0]['doubt'])->toBe('No sé si esta llamada te incluye.')
        ->and($doubtful->agendaTask)->toBeNull();
});

test('a classifier that fails leaves the proposal with just the reply', function () {
    $this->app->instance(ActionClassifier::class, new class implements ActionClassifier
    {
        public function classify(User $user, AlertBrief $brief): array
        {
            throw new RuntimeException('down');
        }
    });
    $item = InboxItem::factory()->for($this->user)->create(['source' => AlertSource::Gmail, 'external_id' => 'demo.1']);

    app(AlertFollowUp::class)->plan($item);

    expect($item->refresh()->proposal['reply'])->not->toBeNull()
        ->and($item->proposal['actions'])->toBe([])
        ->and($item->agendaTask)->toBeNull();
});

test('a doubt is settled with one word: yes prepares it, no takes it off and teaches the classifier', function () {
    Queue::fake([ScheduleTask::class]);
    $item = InboxItem::factory()->for($this->user)->create(['actor_name' => 'Lucía Pons', 'title' => 'Reembolsos', 'proposal' => ['summary' => 'Responder.', 'actions' => [
        (new ClassifiedAction(ClassifiedAction::TASK, ClassifiedAction::DOUBT, 'Documentar los reembolsos', 'Parece una tarea para ti, ¿lo es?'))->toArray('a0'),
        (new ClassifiedAction(ClassifiedAction::CALL, ClassifiedAction::DOUBT, 'Hablarlo con producto', 'No sé si te incluye.', ['people' => ['Lucía'], 'people_sure' => true, 'duration_minutes' => 30]))->toArray('a1'),
    ]]]);
    $settle = fn (string $key, string $answer) => $this->actingAs($this->user)->postJson(route('voice.tools.run', 'settle_alert_action'), ['arguments' => ['alert_id' => $item->id, 'action' => $key, 'answer' => $answer]]);

    $settle('a0', 'no')->assertOk()->assertJsonPath('output.say', 'Vale, lo quito.')->assertJsonPath('canvas.view', 'alerts.review');

    expect(array_column($item->refresh()->proposal['actions'], 'key'))->toBe(['a1'])
        ->and($this->user->intentLessons()->sole()->body)->toContain('no pedía una tarea (Documentar los reembolsos)')
        ->and($item->agendaTask)->toBeNull();

    $settle('a1', 'yes')->assertOk()->assertJsonPath('output.say', 'Vale, lo preparo.');

    expect($item->refresh()->proposal['actions'][0])->toMatchArray(['key' => 'a1', 'confidence' => 'sure'])->not->toHaveKey('doubt')
        ->and($item->agendaTask->title)->toBe('Llamada con Lucía');

    Queue::assertPushed(ScheduleTask::class, fn (ScheduleTask $job): bool => $job->people === ['Lucía']);

    $settle('a9', 'yes')->assertOk()->assertJsonPath('output.error', 'No encuentro esa acción en el aviso.');
});

test('one "sí" sends the reply and books the proposed task, and what is unticked stays out', function () {
    Queue::fake([ExecutePendingAction::class]);
    $this->app->instance(Chats::class, new FakeChats);
    $alert = InboxItem::factory()->for($this->user)->create([
        'source' => AlertSource::Slack,
        'external_id' => 'demo:C01:1001.000400',
        'proposal' => ['summary' => 'Confirmarle que lo revisas.', 'reply' => ['to' => 'thread', 'text' => 'Lo reviso y te confirmo.']],
    ]);
    $booking = fn () => $this->user->agendaTasks()->create([
        'inbox_item_id' => $alert->id, 'origin' => 'alert', 'title' => 'Revisar el contrato', 'status' => AgendaTaskStatus::Proposed,
        'pending_action_id' => app(Actions::class)->propose($this->user, 'calendar.change', 'Reservar «Revisar el contrato» en tu calendario', ['kind' => 'create', 'event' => ['title' => 'Revisar el contrato']])->id,
    ]);
    $task = $booking();

    $this->actingAs($this->user)->postJson(route('alerts.reply', $alert))->assertOk()->assertJsonCount(2, 'actions');

    expect($task->pendingAction->refresh()->status)->toBe(ActionStatus::Confirmed);

    $task->delete();
    $task = $booking();

    $this->actingAs($this->user)->postJson(route('alerts.reply', $alert), ['exclude' => ['task']])->assertOk()->assertJsonCount(1, 'actions')->assertJsonPath('actions.0.type', 'chat.send');

    expect($task->pendingAction->refresh()->status)->toBe(ActionStatus::Pending);

    $this->actingAs($this->user)->postJson(route('alerts.reply', $alert), ['exclude' => ['reply']])->assertOk()->assertJsonCount(1, 'actions')->assertJsonPath('actions.0.type', 'calendar.change');
    $this->actingAs($this->user)->postJson(route('alerts.reply', $alert), ['exclude' => ['reply', 'task']])->assertUnprocessable();
});

test('the classifier has an evaluation set of 25 real messages, scored by the kinds it finds and how sure it is', function () {
    $cases = EvalCase::for('clasificador');
    $scorer = new EvalScorer;
    $sure = fn (string $class): ClassifiedAction => new ClassifiedAction($class, ClassifiedAction::SURE, 'x');

    expect($cases)->toHaveCount(25)
        ->and(collect($cases)->flatMap(fn (EvalCase $case): array => $case->classes ?? [])->unique()->sort()->values()->all())->toBe(['call', 'question', 'task'])
        ->and($scorer->classification(new EvalCase('x', classes: ['task'], confidence: 'sure'), [$sure('task'), $sure('reply')]))->toBe([])
        ->and($scorer->classification(new EvalCase('x', classes: ['question', 'task']), [$sure('call')]))->toBe(['no vio: question, task', 'vio de más: call'])
        ->and($scorer->classification(new EvalCase('x', classes: ['call'], confidence: 'doubt'), [$sure('call')]))->toBe(['debía estar en duda']);
});

test('the evaluation command runs the classifier set', function () {
    $this->artisan('agents:eval', ['agent' => 'clasificador', '--limit' => 1])
        ->expectsOutputToContain('clasificador: 1 de 1 (100 %)')
        ->assertSuccessful();
});
