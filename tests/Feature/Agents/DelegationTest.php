<?php

use App\Agents\AgentRegistry;
use App\Agents\Models\FakeToolModel;
use App\Agents\Models\ModelTurn;
use App\Agents\Orchestrator;
use App\Agents\References\IssuedRefs;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Models\AgentProduction;
use App\Models\AgentRun;
use App\Models\SenseAgent;
use App\Models\User;
use App\Voice\Tools\Toolbox;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->app->instance(Chats::class, $this->chats = new FakeChats);
    $this->model = app(FakeToolModel::class);
    $this->user = User::factory()->create(['name' => 'John León']);
    app(IssuedRefs::class)->remember($this->user, ['message_id' => 'demo:C01:1001.000400']);
});

function voiceCall(string $tool, array $arguments, string $origin = 'voice'): TestResponse
{
    return test()->actingAs(test()->user)->postJson(route('voice.tools.run', $tool), ['arguments' => $arguments, 'timezone' => 'Europe/Madrid', 'origin' => $origin]);
}

/**
 * @param  list<array{agent: string, task: string}>  $steps
 */
function plans(array $steps): ModelTurn
{
    return new ModelTurn(toolCalls: [FakeToolModel::call('plan', ['steps' => $steps])->toolCalls[0]], model: 'fake');
}

test('an agent named by the voice, or the only one whose words appear, gets the ask without the orchestrator thinking', function () {
    $orchestrator = app(Orchestrator::class);

    expect($orchestrator->plan($this->user, 'Lo que sea', 'agenda'))->toBe([['agent' => 'agenda', 'task' => 'Lo que sea']])
        ->and($orchestrator->plan($this->user, 'Contesta a Marta y díselo a Alba'))->toBe([['agent' => 'comunicacion', 'task' => 'Contesta a Marta y díselo a Alba']])
        ->and($orchestrator->plan($this->user, 'Mueve la sync al viernes'))->toBe([['agent' => 'agenda', 'task' => 'Mueve la sync al viernes']])
        ->and($this->model->calls)->toBe([]);
});

test('otherwise a light model plans it, readers first, and a plan it can not give falls back to reading', function () {
    $this->model->script('orquestador', plans([
        ['agent' => 'comunicacion', 'task' => 'Contesta a Marta con lo decidido'],
        ['agent' => 'investigacion', 'task' => 'Qué se decidió del prepago'],
        ['agent' => 'inventado', 'task' => 'Nada'],
    ]));

    $plan = app(Orchestrator::class)->plan($this->user, 'Busca qué se decidió del prepago y contesta a Marta con eso');

    expect(array_column($plan, 'agent'))->toBe(['investigacion', 'comunicacion'])
        ->and($this->model->calls[0]['tools'])->toBe(['plan']);

    $this->model->script('orquestador', new ModelTurn(text: 'No sé.', model: 'fake'));

    expect(app(Orchestrator::class)->plan($this->user, 'Algo raro que nadie entiende'))->toBe([['agent' => 'investigacion', 'task' => 'Algo raro que nadie entiende']]);
});

test('delegating opens the screen at once; readers run first, writers after with their full record, and the voice gets what they said', function () {
    $this->model
        ->script('orquestador', plans([
            ['agent' => 'investigacion', 'task' => 'Qué se decidió del prepago'],
            ['agent' => 'comunicacion', 'task' => 'Contesta a Marta con lo decidido'],
        ]))
        ->script('investigacion', FakeToolModel::call('search_knowledge', ['query' => 'prepago']), FakeToolModel::finish('El paso 3 se queda como está.'))
        ->script('comunicacion',
            FakeToolModel::call('propose_slack_message', ['reply_to' => 'demo:C01:1001.000400', 'text' => 'El paso 3 se queda como está.']),
            fn (array $messages): ModelTurn => FakeToolModel::finish('Te dejo la respuesta a Marta.', [json_decode(collect($messages)->where('role', 'tool')->last()['content'], true)['shown_as']]),
        );

    $delegated = voiceCall('delegate', ['task' => 'Busca qué se decidió del prepago y contesta a Marta con eso'])
        ->assertOk()
        ->assertJsonPath('output.status', 'en marcha')
        ->assertJsonPath('canvas.view', 'agents.run');

    $run = AgentRun::query()->findOrFail($delegated->json('output.run_id'));
    $children = $run->children;
    $writer = collect($this->model->calls)->firstWhere('agent', 'comunicacion');

    expect($run->status)->toBe(AgentRun::DONE)
        ->and($run->say)->toBe('El paso 3 se queda como está. Te dejo la respuesta a Marta.')
        ->and($children->pluck('agent')->all())->toBe(['investigacion', 'comunicacion'])
        ->and($children->pluck('status')->unique()->all())->toBe([AgentRun::DONE])
        ->and($writer['messages'][1]['content'])->toContain('search_knowledge')->toContain('El paso 3 se queda como está.')
        ->and($run->refs)->toHaveCount(1)
        ->and($this->chats->sent)->toBe([]);

    $this->actingAs($this->user)->getJson(route('agents.runs.show', $run))
        ->assertOk()
        ->assertJsonPath('status', 'done')
        ->assertJsonPath('agents.1.agent', 'comunicacion')
        ->assertJsonPath('productions.0.kind', AgentProduction::PROPOSAL)
        ->assertJsonPath('productions.0.canvas.view', 'slack.draft');
});

test('an ask whose agents all fail is a failed ask, and says why', function () {
    $this->model->script('agenda', new ModelTurn(toolCalls: [], text: '', model: 'fake'));
    config(['agents.agents.agenda.max_steps' => 0]);

    $run = AgentRun::query()->findOrFail(voiceCall('delegate', ['task' => 'Mueve la sync al viernes', 'agent' => 'agenda'])->json('output.run_id'));

    expect($run->status)->toBe(AgentRun::FAILED)->and($run->error)->toBe('Agenda ha dado demasiados pasos sin terminar.');
});

test('the voice shows what agents made by its reference, and only the person\'s own', function () {
    $production = AgentProduction::factory()->for($this->user)->create();
    $other = AgentProduction::factory()->create();

    voiceCall('present', ['ref' => $production->ref])->assertJsonPath('canvas.view', 'openui.view')->assertJsonPath('output.what', 'Uso de la semana');
    voiceCall('present', ['ref' => $other->ref])->assertJsonPath('canvas', null);
    voiceCall('present', ['ref' => 'prod_inventada'])->assertJsonPath('output.error', fn (string $error): bool => str_contains($error, 'No hay nada preparado'));
    $this->actingAs($this->user)->getJson(route('agents.runs.show', AgentRun::factory()->create()))->assertNotFound();
});

test('the voice reads and shows itself; anything that writes is the agents\', while the screen can still use it', function () {
    voiceCall('propose_slack_message', ['reply_to' => 'demo:C01:1001.000400', 'text' => 'Hola'])
        ->assertJsonPath('output.error', 'Eso lo hacen los agentes: llama a delegate con el encargo.');

    voiceCall('propose_slack_message', ['reply_to' => 'demo:C01:1001.000400', 'text' => 'Hola'], 'screen')->assertJsonPath('canvas.view', 'slack.draft');
});

test('every tool the voice and the agents are given exists, and no agent can delegate or present', function () {
    $toolbox = app(Toolbox::class);
    $named = [...config('agents.voice_tools'), ...collect(config('agents.agents'))->flatMap(fn (array $agent): array => $agent['tools'])->all(), ...config('agents.archive.tools')];

    expect(collect($named)->unique()->reject(fn (string $name): bool => $toolbox->find($name) !== null)->values()->all())->toBe([])
        ->and(collect(config('agents.agents'))->flatMap(fn (array $agent): array => $agent['tools'])->intersect(['delegate', 'present'])->all())->toBe([]);
});

test('each agent has its own model and weights: changing one leaves the rest as they were', function () {
    config(['agents.agents.comunicacion.model' => 'deepseek-otro', 'agents.agents.comunicacion.effort' => 'max']);
    $registry = app(AgentRegistry::class);

    expect($registry->find($this->user, 'comunicacion')->model)->toBe('deepseek-otro')
        ->and($registry->find($this->user, 'comunicacion')->effort)->toBe('max')
        ->and($registry->find($this->user, 'redaccion')->model)->toBe(config('agents.tiers.deepseek.capable'))
        ->and($registry->find($this->user, 'agenda')->model)->toBe(config('agents.tiers.deepseek.light'))
        ->and($registry->find($this->user, 'investigacion')->readsOnly())->toBeTrue()
        ->and($registry->find($this->user, 'comunicacion')->readsOnly())->toBeFalse();
});

test('an agent of the Archive runs as an agent of its own, with its instructions and the model its front matter asks for', function () {
    SenseAgent::factory()->create(['user_id' => $this->user->id, 'slug' => 'prepago', 'name' => 'prepago', 'description' => 'Lleva el prepago.', 'instructions' => 'Responde siempre con el estado del prepago.', 'model' => 'haiku']);
    $this->model->script('archivo:prepago', FakeToolModel::finish('El prepago va en el paso 3.'));

    $agent = app(AgentRegistry::class)->find($this->user, 'archivo:prepago');
    $run = AgentRun::query()->findOrFail(voiceCall('delegate', ['task' => '¿Cómo va el prepago?', 'agent' => 'archivo:prepago'])->json('output.run_id'));

    expect($agent->model)->toBe(config('agents.tiers.deepseek.light'))
        ->and($agent->tools)->toContain('use_sense_skill', 'search_knowledge')
        ->and($run->say)->toBe('El prepago va en el paso 3.')
        ->and(collect($this->model->calls)->firstWhere('agent', 'archivo:prepago')['messages'][0]['content'])->toContain('Responde siempre con el estado del prepago.')
        ->and(AgentRegistry::model('deepseek', 'deepseek-especial'))->toBe('deepseek-especial');
});
