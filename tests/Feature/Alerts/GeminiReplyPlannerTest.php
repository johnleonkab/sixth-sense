<?php

use App\Alerts\AlertSource;
use App\Alerts\Replies\AlertBrief;
use App\Alerts\Replies\GeminiReplyPlanner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function geminiPlan(array $answer): void
{
    Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($answer)]]]]]])]);
}

beforeEach(function () {
    $this->planner = new GeminiReplyPlanner(['api_key' => 'key', 'base_url' => 'https://gemini.test', 'model' => 'flash', 'thinking_level' => 'low', 'timeout' => 10]);
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

test('the engine proposes whom to answer, what to say and what else to do', function () {
    geminiPlan([
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
        ->and($plan->question)->toBeNull();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://gemini.test/v1beta/models/flash:generateContent'
        && str_contains($request['contents'][0]['parts'][0]['text'], 'Participan también: Marta Ruiz')
        && str_contains($request['systemInstruction']['parts'][0]['text'], '"all" (a todos los del email)'));
});

test('no written answer, or an audience that does not exist, means no reply draft', function (string $audience) {
    geminiPlan(['summary' => 'Llamarle.', 'reply_to' => $audience, 'reply_text' => 'Hola', 'other_actions' => [], 'question' => '¿Le llamas hoy?']);

    $plan = $this->planner->plan($this->user, $this->brief);

    expect($plan->reply)->toBeNull()->and($plan->question)->toBe('¿Le llamas hoy?');
})->with(['none', 'everyone']);

test('an answer without a summary is an error, so the alert stays without a proposal', function () {
    geminiPlan(['reply_to' => 'sender']);

    $this->planner->plan($this->user, $this->brief);
})->throws(RuntimeException::class);
