<?php

use App\Agents\AgentRegistry;
use App\Agents\AgentRunner;
use App\Agents\Models\FakeToolModel;
use App\Agents\Models\ModelTurn;
use App\Agents\References\IssuedRefs;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\Mailbox;
use App\Models\AgentProduction;
use App\Models\AgentRun;
use App\Models\PendingAction;
use App\Models\User;

beforeEach(function () {
    $this->app->instance(Chats::class, $this->chats = new FakeChats);
    $this->app->instance(Mailbox::class, new FakeMailbox);
    $this->model = app(FakeToolModel::class);
    $this->user = User::factory()->create(['name' => 'John León']);
    // The message is on screen: the server handed its id out.
    app(IssuedRefs::class)->remember($this->user, ['message_id' => 'demo:C01:1001.000400']);
});

function runAgent(string $agent, string $task = 'Contesta a Marta que lo reviso esta tarde', string $context = ''): AgentRun
{
    $user = test()->user;
    $run = $user->agentRuns()->create(['agent' => $agent, 'task' => $task, 'status' => AgentRun::QUEUED]);

    return app(AgentRunner::class)->run(app(AgentRegistry::class)->find($user, $agent), $user, $run, $context);
}

/**
 * The reference the last tool result put on screen, as the model reads it.
 *
 * @param  list<array<string, mixed>>  $messages
 */
function shownAs(array $messages): string
{
    return json_decode(collect($messages)->where('role', 'tool')->last()['content'], true)['shown_as'];
}

test('an agent proposes a reply, nothing is sent, and it answers with what it said and the reference to show', function () {
    $this->model->script('comunicacion',
        FakeToolModel::call('propose_slack_message', ['reply_to' => 'demo:C01:1001.000400', 'text' => 'Lo reviso esta tarde.']),
        fn (array $messages): ModelTurn => FakeToolModel::finish('Te dejo la respuesta a Marta para que la confirmes.', [shownAs($messages)]),
    );

    $run = runAgent('comunicacion');
    $production = $run->productions()->sole();

    expect($run->status)->toBe(AgentRun::DONE)
        ->and($run->say)->toBe('Te dejo la respuesta a Marta para que la confirmes.')
        ->and($run->refs)->toBe([$production->ref])
        ->and($production->kind)->toBe(AgentProduction::PROPOSAL)
        ->and($production->pending_action_id)->toBe(PendingAction::sole()->id)
        ->and($production->canvas['view'])->toBe('slack.draft')
        ->and($run->steps[0]['tool'])->toBe('propose_slack_message')
        ->and($run->input_tokens)->toBe(200)
        ->and($this->chats->sent)->toBe([]);
});

test('an agent only gets its own tools, and one it asks for that is not its own is refused', function () {
    $this->model->script('agenda',
        FakeToolModel::call('propose_slack_message', ['reply_to' => 'demo:C01:1001.000400', 'text' => 'Hola']),
        FakeToolModel::finish('No he podido.'),
    );

    $run = runAgent('agenda');

    expect($this->model->calls[0]['tools'])->toContain('propose_new_event', 'finish')->not->toContain('propose_slack_message', 'delegate')
        ->and($run->steps[0]['error'])->toBe('not allowed')
        ->and(PendingAction::query()->count())->toBe(0);
});

test('an agent can not point at an id no tool handed out', function () {
    $this->model->script('comunicacion',
        FakeToolModel::call('propose_slack_message', ['reply_to' => 'demo:C01:9999.000001', 'text' => 'Hola']),
        FakeToolModel::finish('No encuentro ese mensaje.'),
    );

    $run = runAgent('comunicacion');

    expect($run->steps[0]['error'])->toContain('reply_to no ha salido de ninguna búsqueda')
        ->and(PendingAction::query()->count())->toBe(0);
});

test('references the model makes up are dropped: only what the run produced is shown', function () {
    $this->model->script('comunicacion',
        FakeToolModel::call('propose_slack_reaction', ['message_id' => 'demo:C01:1001.000400', 'reaction' => '+1']),
        fn (array $messages): ModelTurn => FakeToolModel::finish('Listo.', [shownAs($messages), 'prod_inventada']),
    );

    $run = runAgent('comunicacion');

    expect($run->refs)->toBe([$run->productions()->sole()->ref]);
});

test('the reasoning of each round goes back to the model with the next one', function () {
    $this->model->script('comunicacion',
        new ModelTurn(toolCalls: [FakeToolModel::call('list_alerts')->toolCalls[0]], reasoning: 'Primero miro los avisos.', model: 'fake'),
        FakeToolModel::finish('No hay nada pendiente.'),
    );

    runAgent('comunicacion');

    $second = $this->model->calls[1]['messages'];

    expect(collect($second)->firstWhere('role', 'assistant')['reasoning'])->toBe('Primero miro los avisos.')
        ->and(collect($second)->firstWhere('role', 'tool')['name'])->toBe('list_alerts');
});

test('an agent that never finishes stops at its step limit, and one over budget stops too', function () {
    config(['agents.agents.agenda.max_steps' => 2]);
    $this->model->script('agenda', FakeToolModel::call('find_calendar_events', ['start_date' => '2026-10-08']), FakeToolModel::call('find_calendar_events', ['start_date' => '2026-10-09']));

    expect(runAgent('agenda')->only(['status', 'error']))->toBe(['status' => AgentRun::FAILED, 'error' => 'Agenda ha dado demasiados pasos sin terminar.']);

    config(['agents.prices.deepseek-v4-pro' => ['input' => 1000, 'output' => 1000], 'agents.agents.comunicacion.budget_run' => 0.01]);
    $this->model->script('comunicacion', FakeToolModel::call('list_alerts'), FakeToolModel::finish('Hecho.'));

    $run = runAgent('comunicacion');

    expect($run->status)->toBe(AgentRun::FAILED)
        ->and($run->error)->toBe('Comunicación ha superado su presupuesto por encargo.')
        ->and($run->cost_usd)->toBe(0.12);
});

test('what agents that ran before decided reaches the next one in full', function () {
    $this->model->script('comunicacion', FakeToolModel::finish('Listo.'));

    runAgent('comunicacion', 'Contesta a Marta con lo decidido', "— investigacion: «¿Qué se decidió?»\n  · search_knowledge({\"query\":\"prepago\"}) → {\"results\":[]}\n  Concluyó: El paso 3 se queda.");

    expect($this->model->calls[0]['messages'][1]['content'])->toContain('search_knowledge')->toContain('El paso 3 se queda.')->toContain('Encargo: Contesta a Marta con lo decidido')
        ->and($this->model->calls[0]['messages'][0]['content'])->toContain('Eres Comunicación')->toContain('nunca te los inventes');
});

test('the Vistas agent is told how to compose views, and its views are checked before they are shown', function () {
    $this->model->script('vistas',
        FakeToolModel::call('compose_view', ['title' => 'Uso', 'ui' => 'root = View("Uso", [m])\nm = Nada("x")']),
        FakeToolModel::call('compose_view', ['title' => 'Uso', 'ui' => "root = View(\"Uso\", [m])\nm = Metric(\"Usuarios\", \"1.204\")"]),
        fn (array $messages): ModelTurn => FakeToolModel::finish('Ahí lo tienes.', [shownAs($messages)]),
    );

    $run = runAgent('vistas', 'Cuántos usuarios hay');

    expect($this->model->calls[0]['messages'][0]['content'])->toContain('View(title: string')
        ->and($run->steps[0]['error'])->toContain('La vista no es válida')
        ->and($run->productions()->sole()->canvas['view'])->toBe('openui.view');
})->skip(fn (): bool => trim((string) shell_exec('command -v node')) === '', 'Node is not installed.');
