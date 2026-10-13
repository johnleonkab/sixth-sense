<?php

use App\Models\User;
use App\Scheduling\Assessors\GeminiTaskAssessor;
use App\Scheduling\SchedulingRequest;
use App\Scheduling\TaskAssessment;
use App\Scheduling\Urgency;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function assessorAnswers(array $answer): void
{
    Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($answer)]]]]]])]);
}

function assess(SchedulingRequest $request, ?string $workContext = null): TaskAssessment
{
    $assessor = new GeminiTaskAssessor(['api_key' => 'key', 'base_url' => 'https://gemini.test', 'model' => 'flash', 'thinking_level' => 'low', 'timeout' => 10]);

    return $assessor->assess(User::factory()->make(['name' => 'John León']), $request, CarbonImmutable::parse('2026-10-14 08:00', 'Europe/Madrid'), $workContext, ['mié 14 oct: 5 reuniones, 3.3 h ocupadas']);
}

test('it decides urgency, length and kind, from the task, the calendar and what the person works on', function () {
    assessorAnswers(['title' => 'Revisar el PRD del prepago', 'urgency' => 'today', 'reason' => 'Marta lo necesita para la reunión de mañana.', 'duration_minutes' => 50, 'kind' => 'task', 'deadline' => '2026-10-15', 'people' => [], 'description' => 'PRD en Notion.']);

    $assessment = assess(new SchedulingRequest('Revisar el PRD', 'Marta lo pide para mañana'), 'Cerrando el lanzamiento del prepago.');

    expect($assessment->urgency)->toBe(Urgency::Today)
        ->and($assessment->durationMinutes)->toBe(50)
        ->and($assessment->deadline->toDateString())->toBe('2026-10-15')
        ->and($assessment->kind)->toBe('task');

    Http::assertSent(fn (Request $request) => str_contains($text = $request['contents'][0]['parts'][0]['text'], 'Cerrando el lanzamiento del prepago.')
        && str_contains($text, 'mié 14 oct: 5 reuniones')
        && str_contains($text, 'Marta lo pide para mañana'));
});

test('what the person said wins, and odd answers are kept within bounds', function () {
    assessorAnswers(['title' => 'Hablar con Marta', 'urgency' => 'whenever', 'reason' => 'x', 'duration_minutes' => 900, 'kind' => 'meeting', 'deadline' => 'pronto', 'people' => [], 'description' => '']);

    $assessment = assess(new SchedulingRequest('Hablar con Marta', urgency: Urgency::Today));

    expect($assessment->urgency)->toBe(Urgency::Today)
        ->and($assessment->durationMinutes)->toBe(240)
        ->and($assessment->deadline)->toBeNull()
        // A meeting with nobody is the person's own task.
        ->and($assessment->kind)->toBe('task');
});

test('an unexpected answer is an error, so the scheduler uses its defaults', function () {
    assessorAnswers(['urgency' => 'someday']);

    assess(new SchedulingRequest('Algo'));
})->throws(RuntimeException::class);
