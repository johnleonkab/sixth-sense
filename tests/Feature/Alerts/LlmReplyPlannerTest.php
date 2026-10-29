<?php

use App\Alerts\AlertSource;
use App\Alerts\Replies\AlertBrief;
use App\Alerts\Replies\LlmReplyPlanner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Support\RecordingTextModel;

beforeEach(function () {
    $this->model = new RecordingTextModel([]);
    $this->planner = new LlmReplyPlanner($this->model);
    $this->user = User::factory()->create(['name' => 'John León']);
    $this->brief = new AlertBrief(
        source: AlertSource::Gmail,
        where: 'Email',
        from: 'Julián Soto <julian@cm.com>',
        subject: 'Lanzamiento',
        text: '¿Llegamos al lanzamiento del lunes? Si no, vemos otra fecha.',
        receivedAt: CarbonImmutable::parse('2026-10-08 09:00'),
        participants: ['Marta Ruiz'],
    );
});

function planAnswers(array $answer): void
{
    test()->model->answers = $answer;
}

test('the engine proposes whom to answer, what to say and what else to do', function () {
    planAnswers([
        'summary' => 'Responder a todos que no se llega y proponer el jueves.',
        'reply_to' => 'all',
        'reply_text' => "Hola Julián,\nno llegamos al lunes. ¿Lo movemos al jueves?",
        'other_actions' => ['Crear un evento el jueves a las 10 con Julián y Marta'],
        'question' => '',
    ]);

    $plan = $this->planner->plan($this->user, $this->brief);

    expect($plan->summary)->toBe('Responder a todos que no se llega y proponer el jueves.')
        ->and($plan->reply)->toBe(['to' => 'all', 'text' => "Hola Julián,\nno llegamos al lunes. ¿Lo movemos al jueves?"])
        ->and($plan->otherActions)->toBe(['Crear un evento el jueves a las 10 con Julián y Marta'])
        ->and($plan->question)->toBeNull()
        ->and($this->model->calls[0]['user'])->toContain('Participan también: Marta Ruiz')
        ->and($this->model->calls[0]['system'])->toContain('"all" (a todos los del email)');
});

test('no written answer, or an audience that does not exist, means no reply draft', function (string $audience) {
    planAnswers(['summary' => 'Llamarle.', 'reply_to' => $audience, 'reply_text' => 'Hola', 'other_actions' => [], 'question' => '¿Le llamas hoy?']);

    $plan = $this->planner->plan($this->user, $this->brief);

    expect($plan->reply)->toBeNull()->and($plan->question)->toBe('¿Le llamas hoy?');
})->with(['none', 'everyone']);

test('an answer without a summary is an error, so the alert stays without a proposal', function () {
    planAnswers(['reply_to' => 'sender']);

    $this->planner->plan($this->user, $this->brief);
})->throws(RuntimeException::class);

test('work the person has to do themselves comes with the proposal, for the scheduler', function () {
    planAnswers([
        'summary' => 'Responder que lo revisas hoy.', 'reply_to' => 'sender', 'reply_text' => 'Lo reviso hoy.', 'other_actions' => [], 'question' => '',
        'manual_task' => ['needed' => true, 'title' => 'Revisar el contrato de Grupo Vela', 'effort_minutes' => 45, 'deadline' => '2026-10-16', 'people' => []],
    ]);

    expect($this->planner->plan($this->user, $this->brief)->task)->toBe(['title' => 'Revisar el contrato de Grupo Vela', 'effort_minutes' => 45, 'deadline' => '2026-10-16', 'people' => []]);
});

test('when answering is enough, there is no task to schedule', function () {
    planAnswers(['summary' => 'Responder.', 'reply_to' => 'sender', 'reply_text' => 'Ok', 'other_actions' => [], 'question' => '', 'manual_task' => ['needed' => false, 'title' => '', 'effort_minutes' => 0, 'deadline' => '', 'people' => []]]);

    expect($this->planner->plan($this->user, $this->brief)->task)->toBeNull();
});
