<?php

use App\Agents\Evals\EvalCase;
use App\Agents\Evals\EvalScorer;
use App\Agents\Models\FakeToolModel;
use App\Agents\Models\ModelTurn;
use App\Alerts\RelevanceJudge;
use App\Alerts\Replies\ReplyPlanner;
use App\Jobs\Middleware\LimitAgentConcurrency;
use App\Models\AgentProduction;
use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

test('an agent at its concurrency limit waits its turn instead of running', function () {
    $job = new class
    {
        public ?int $released = null;

        public bool $ran = false;

        public function release(int $seconds): void
        {
            $this->released = $seconds;
        }
    };

    $busy = Cache::lock('agent-slot:redaccion:0', 60);
    $busy->get();

    (new LimitAgentConcurrency('redaccion', 1))->handle($job, function (object $job): void {
        $job->ran = true;
    });

    expect($job->ran)->toBeFalse()->and($job->released)->toBe(5);

    $busy->release();

    (new LimitAgentConcurrency('redaccion', 1))->handle($job, function (object $job): void {
        $job->ran = true;
    });

    expect($job->ran)->toBeTrue()->and(Cache::lock('agent-slot:redaccion:0', 60)->get())->toBeTrue();
});

test('every agent has about twenty evaluation cases, and the orchestrator\'s name existing agents', function () {
    foreach (['orquestador', 'comunicacion', 'agenda', 'investigacion', 'redaccion', 'integraciones', 'vistas'] as $agent) {
        expect(EvalCase::for($agent))->toHaveCount(20);
    }

    $agents = collect(EvalCase::for('orquestador'))->flatMap(fn (EvalCase $case): array => $case->agents)->unique();

    expect($agents->diff(array_keys(config('agents.agents')))->all())->toBe([]);
});

test('a run is scored by its outcome: finished, proposed when it should, and never with a tool it must not use', function () {
    $scorer = new EvalScorer;
    $run = AgentRun::factory()->done('Te dejo la respuesta.')->create(['agent' => 'comunicacion', 'steps' => [['tool' => 'search_slack_messages'], ['tool' => 'propose_slack_message']]]);
    AgentProduction::factory()->create(['user_id' => $run->user_id, 'agent_run_id' => $run->id, 'kind' => AgentProduction::PROPOSAL]);

    expect($scorer->run(new EvalCase('Contesta a Marta', anyTool: ['propose_slack_message'], proposes: true), $run))->toBe([])
        ->and($scorer->run(new EvalCase('¿Qué dijo Marta?', noTool: ['propose_slack_message'], proposes: false), $run))->toBe(['usó lo que no debía: propose_slack_message', 'dejó una propuesta que no tocaba'])
        ->and($scorer->run(new EvalCase('Convoca', anyTool: ['propose_new_event']), $run))->toBe(['no usó ninguna de: propose_new_event'])
        ->and($scorer->plan(new EvalCase('x', agents: ['investigacion', 'comunicacion']), [['agent' => 'comunicacion', 'task' => 'x'], ['agent' => 'agenda', 'task' => 'y']]))
        ->toBe(['no lo pasó a: investigacion', 'lo pasó de más a: agenda']);
});

test('the evaluation command runs an agent\'s cases with its model and reports how many pass', function () {
    User::factory()->create();
    app(FakeToolModel::class)->script('vistas', fn (): ModelTurn => FakeToolModel::finish('Sin datos.'));

    $this->artisan('agents:eval', ['agent' => 'vistas', '--limit' => 1])
        ->expectsOutputToContain('no usó ninguna de: compose_view')
        ->expectsOutputToContain('vistas: 0 de 1 (0 %)')
        ->assertSuccessful();

    $this->artisan('agents:eval', ['agent' => 'orquestador', '--limit' => 2])
        ->expectsOutputToContain('orquestador: 2 de 2 (100 %)')
        ->assertSuccessful();
});

test('each pipeline takes its model from its own entry: changing one leaves the others as they were', function () {
    config(['alerts.planner' => 'llm', 'alerts.judge' => 'llm', 'ai.text' => 'deepseek', 'agents.pipelines.reply_planner.model' => 'deepseek-planner']);

    $config = fn (object $textModel): array => (fn () => $this->config)->call($textModel);

    $planner = (fn () => $this->model)->call(app(ReplyPlanner::class));
    $judge = (fn () => $this->model)->call(app(RelevanceJudge::class));

    expect($config($planner)['model'])->toBe('deepseek-planner')
        ->and($config($judge)['model'])->toBe(config('ai.providers.deepseek.light_model'));
});

test('agents have their own queues, a light one and a heavy one, and production stays within eight workers', function () {
    $defaults = config('horizon.defaults');
    $production = config('horizon.environments.production');

    expect($defaults['supervisor-agents-light']['queue'])->toBe(['agents-light'])
        ->and($defaults['supervisor-agents-heavy']['queue'])->toBe(['agents-heavy'])
        ->and($defaults['supervisor-agents-heavy']['timeout'])->toBeLessThan(config('queue.connections.redis.retry_after'))
        ->and(collect($defaults)->sum(fn (array $supervisor, string $name): int => $production[$name]['maxProcesses'] ?? $supervisor['maxProcesses']))->toBe(8)
        ->and(collect(config('agents.agents'))->pluck('queue')->unique()->sort()->values()->all())->toBe(['agents-heavy', 'agents-light']);
});
