<?php

use App\Models\User;
use App\Scheduling\Assessors\LlmTaskAssessor;
use App\Scheduling\SchedulingRequest;
use App\Scheduling\TaskAssessment;
use App\Scheduling\Urgency;
use Carbon\CarbonImmutable;
use Tests\Support\RecordingTextModel;

function assess(SchedulingRequest $request, ?string $workContext = null): TaskAssessment
{
    return test()->assessor->assess(User::factory()->make(['name' => 'John León']), $request, CarbonImmutable::parse('2026-10-14 08:00', 'Europe/Madrid'), $workContext, ['mié 14 oct: 5 reuniones, 3.3 h ocupadas']);
}

beforeEach(function () {
    $this->model = new RecordingTextModel([]);
    $this->assessor = new LlmTaskAssessor($this->model);
});

test('it decides urgency, length and kind, from the task, the calendar and what the person works on', function () {
    $this->model->answers = ['title' => 'Revisar el PRD del prepago', 'urgency' => 'today', 'reason' => 'Marta lo necesita para la reunión de mañana.', 'duration_minutes' => 50, 'kind' => 'task', 'deadline' => '2026-10-15', 'people' => [], 'description' => 'PRD en Notion.'];

    $assessment = assess(new SchedulingRequest('Revisar el PRD', 'Marta lo pide para mañana'), 'Cerrando el lanzamiento del prepago.');

    expect($assessment->urgency)->toBe(Urgency::Today)
        ->and($assessment->durationMinutes)->toBe(50)
        ->and($assessment->deadline->toDateString())->toBe('2026-10-15')
        ->and($assessment->kind)->toBe('task')
        ->and($this->model->calls[0]['user'])->toContain('Cerrando el lanzamiento del prepago.')
        ->and($this->model->calls[0]['user'])->toContain('mié 14 oct: 5 reuniones')
        ->and($this->model->calls[0]['user'])->toContain('Marta lo pide para mañana');
});

test('what the person said wins, and odd answers are kept within bounds', function () {
    $this->model->answers = ['title' => 'Hablar con Marta', 'urgency' => 'whenever', 'reason' => 'x', 'duration_minutes' => 900, 'kind' => 'meeting', 'deadline' => 'pronto', 'people' => [], 'description' => ''];

    $assessment = assess(new SchedulingRequest('Hablar con Marta', urgency: Urgency::Today));

    expect($assessment->urgency)->toBe(Urgency::Today)
        ->and($assessment->durationMinutes)->toBe(240)
        ->and($assessment->deadline)->toBeNull()
        // A meeting with nobody is the person's own task.
        ->and($assessment->kind)->toBe('task');
});

test('an unexpected answer is an error, so the scheduler uses its defaults', function () {
    $this->model->answers = ['urgency' => 'someday'];

    assess(new SchedulingRequest('Algo'));
})->throws(RuntimeException::class);
