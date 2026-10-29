<?php

use App\Actions\Actions;
use App\Actions\ActionStatus;
use App\Models\GithubRepository;
use App\Models\PendingAction;
use App\Models\SenseAgent;
use App\Sense\Scaffold;
use App\Voice\AssistantProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->repository = GithubRepository::factory()->writable()->create([
        'owner' => 'jhon',
        'name' => 'sense',
        'full_name' => 'jhon/sense',
        'is_work' => true,
    ]);
    $this->user = $this->repository->account->user;
});

test('a document is refused when it is missing what its type requires', function () {
    Http::fake([
        'api.github.com/*/contents/types/prd.yml*' => Http::response([
            'content' => base64_encode(Scaffold::files()['types/prd.yml']),
            'sha' => 't',
        ]),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'propose_sense_file'), ['arguments' => [
            'path' => 'projects/prepago/docs/2026-10-23-cobro.md',
            'contents' => "---\ntype: prd\nauthor: Ana\nwhy: Porque el cobro falla.\n---\n\n# Cobro\n",
            'message' => 'Añade el brief',
        ]])
        ->assertJsonPath('output.error', fn (string $error): bool => str_contains($error, 'outcome'));

    expect(PendingAction::query()->count())->toBe(0);
});

test('a document in the wrong folder is refused', function () {
    Http::fake([
        'api.github.com/*/contents/types/prd.yml*' => Http::response([
            'content' => base64_encode(Scaffold::files()['types/prd.yml']),
            'sha' => 't',
        ]),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'propose_sense_file'), ['arguments' => [
            'path' => 'inbox/2026-10-23-cobro.md',
            'contents' => "---\ntype: prd\nauthor: Ana\nwhy: Porque\noutcome: Cobrar\n---\n\n# Cobro\n",
            'message' => 'Añade el brief',
        ]])
        ->assertJsonPath('output.error', fn (string $error): bool => str_contains($error, 'projects/{project}/docs'));
});

test('a confirmed file is committed, and a new agent is copied into sixth-sense', function () {
    $markdown = <<<'MD'
        ---
        type: agent
        name: Prepago
        purpose: Lleva el prepago.
        when: Cuando hablen de cobros.
        ---

        Habla corto.
        MD;
    $reads = 0;

    Http::fake(function (Request $request) use (&$reads, $markdown) {
        if (str_contains($request->url(), '/contents/types/agent.yml')) {
            return Http::response(['content' => base64_encode(Scaffold::files()['types/agent.yml']), 'sha' => 't']);
        }

        if (str_contains($request->url(), '/git/trees/')) {
            return Http::response(['tree' => [['path' => 'agents/prepago/agent.md', 'type' => 'blob']]]);
        }

        if (str_contains($request->url(), '/contents/agents/prepago/agent.md')) {
            if ($request->method() === 'GET' && $reads++ === 0) {
                return Http::response([], 404);
            }

            if ($request->method() === 'GET') {
                return Http::response(['content' => base64_encode($markdown), 'sha' => 'new']);
            }

            return Http::response([
                'content' => ['path' => 'agents/prepago/agent.md', 'sha' => 'new'],
                'commit' => ['sha' => 'cafebabe', 'html_url' => 'https://github.com/jhon/sense/commit/cafebabe'],
            ]);
        }

        return Http::response('unexpected', 500);
    });

    $proposal = $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'propose_sense_file'), ['arguments' => [
            'path' => 'agents/prepago/agent.md',
            'contents' => $markdown,
            'message' => 'Añade el agente de prepago',
        ]])
        ->assertJsonPath('canvas.view', 'sense.file')
        ->assertJsonPath('canvas.props.removing', false);

    $this->actingAs($this->user)->postJson(route('actions.confirm', $proposal->json('output.pending_action_id')))->assertOk();

    $agent = SenseAgent::query()->where('user_id', $this->user->id)->where('slug', 'prepago')->first();

    expect($agent)->not->toBeNull()
        ->and($agent->name)->toBe('Prepago')
        ->and($agent->cue)->toBe('Cuando hablen de cobros.')
        ->and($agent->instructions)->toBe('Habla corto.');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request['message'] === 'Añade el agente de prepago'
        && ! array_key_exists('sha', $request->data()));
});

test('deleting a file is proposed and then removed', function () {
    Http::fake([
        'api.github.com/*/contents/inbox/2026-10-23-nota.md*' => Http::sequence()
            ->push(['content' => base64_encode("nota\n"), 'sha' => 'abc'])
            ->push(['content' => base64_encode("nota\n"), 'sha' => 'abc'])
            ->push(['commit' => ['sha' => 'def', 'html_url' => 'https://github.com/jhon/sense/commit/def']]),
    ]);

    $proposal = $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'propose_sense_delete'), ['arguments' => [
            'path' => 'inbox/2026-10-23-nota.md',
            'message' => 'Quita la nota',
        ]])
        ->assertJsonPath('canvas.props.removing', true);

    $this->actingAs($this->user)->postJson(route('actions.confirm', $proposal->json('output.pending_action_id')))->assertOk();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request['sha'] === 'abc');
});

test('focusing an agent returns its references with the instructions', function () {
    SenseAgent::factory()->create([
        'user_id' => $this->user->id,
        'slug' => 'jira-writer',
        'name' => 'Jira',
        'instructions' => 'Sigue la plantilla.',
        'references' => "# references/contexto.md\n\nPilares.",
    ]);

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'focus_sense_agent'), ['arguments' => ['slug' => 'jira-writer']])
        ->assertJsonPath('output.instructions', fn (string $text): bool => str_contains($text, 'Sigue la plantilla.')
            && str_contains($text, 'references/contexto.md')
            && str_contains($text, 'Pilares.'));
});

test('syncing an agent copies the markdown in its references folder', function () {
    $agent = "---\ntype: agent\nname: Jira\npurpose: Escribe historias.\nwhen: Cuando pidan una historia.\n---\n\nSigue la plantilla.\n";

    Http::fake([
        'api.github.com/*/git/trees/*' => Http::response(['tree' => [
            ['path' => 'agents/jira-writer/agent.md', 'type' => 'blob'],
            ['path' => 'agents/jira-writer/references/plantilla.md', 'type' => 'blob'],
            ['path' => 'agents/jira-writer/references/contexto.md', 'type' => 'blob'],
        ]]),
        'api.github.com/*/contents/agents/jira-writer/agent.md*' => Http::response([
            'content' => base64_encode($agent),
            'sha' => 'a',
        ]),
        'api.github.com/*/contents/agents/jira-writer/references/contexto.md*' => Http::response([
            'content' => base64_encode("# Contexto\n\nPilares.\n"),
            'sha' => 'c',
        ]),
        'api.github.com/*/contents/agents/jira-writer/references/plantilla.md*' => Http::response([
            'content' => base64_encode("# Plantilla\n\nComo rol.\n"),
            'sha' => 'p',
        ]),
    ]);

    $this->actingAs($this->user)
        ->post(route('settings.connections.sense.sync', [$this->repository->account, $this->repository]))
        ->assertRedirect();

    $stored = $this->user->senseAgents()->where('slug', 'jira-writer')->first();

    expect($stored)->not->toBeNull()
        ->and($stored->instructions)->toBe('Sigue la plantilla.')
        ->and($stored->references)->toBe("# references/contexto.md\n\n# Contexto\n\nPilares.\n\n# references/plantilla.md\n\n# Plantilla\n\nComo rol.")
        ->and($stored->briefing())->toContain('Sigue la plantilla.')
        ->and($stored->briefing())->toContain('Pilares.')
        ->and($stored->briefing())->toContain('Como rol.');
});

test('focusing an agent puts its name on screen and returns its instructions', function () {
    SenseAgent::factory()->create([
        'user_id' => $this->user->id,
        'slug' => 'prepago',
        'name' => 'Prepago',
        'instructions' => 'Habla corto.',
    ]);

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'focus_sense_agent'), ['arguments' => ['slug' => 'prepago']])
        ->assertJsonPath('canvas.view', 'sense.agent')
        ->assertJsonPath('canvas.props.name', 'Prepago')
        ->assertJsonPath('output.instructions', 'Habla corto.');

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'focus_sense_agent'), ['arguments' => ['slug' => '']])
        ->assertJsonPath('canvas.props.name', null);
});

test('the assistant is told which agents it can hand the turn to', function () {
    SenseAgent::factory()->create([
        'user_id' => $this->user->id,
        'slug' => 'prepago',
        'name' => 'Prepago',
        'purpose' => 'Lleva el prepago.',
        'cue' => 'Cuando hablen de cobros.',
    ]);

    $profile = AssistantProfile::for($this->user, CarbonImmutable::parse('2026-10-23 12:00', 'UTC'));

    expect($profile->instructions)->toContain('jhon/sense')
        ->and($profile->instructions)->toContain('prepago: Prepago')
        ->and($profile->instructions)->toContain('focus_sense_agent');
});

test('an agent that disappeared from the repository is dropped on sync', function () {
    SenseAgent::factory()->create(['user_id' => $this->user->id, 'slug' => 'viejo']);

    Http::fake([
        'api.github.com/*/git/trees/*' => Http::response(['tree' => [
            ['path' => 'agents/keeper/agent.md', 'type' => 'blob'],
        ]]),
        'api.github.com/*/contents/agents/keeper/agent.md*' => Http::response([
            'content' => base64_encode(Scaffold::files()['agents/keeper/agent.md']),
            'sha' => 'k',
        ]),
    ]);

    $this->actingAs($this->user)
        ->post(route('settings.connections.sense.sync', [$this->repository->account, $this->repository]))
        ->assertRedirect();

    expect($this->user->senseAgents()->pluck('slug')->all())->toBe(['keeper']);
});

test('rewriting a markdown proposal commits that text instead of the original', function () {
    $edited = "---\ntype: note\nauthor: Ana\n---\n\nReescrito a mano.\n";
    $action = app(Actions::class)->propose($this->user, 'sense.write', 'Guardar nota', [
        'path' => 'inbox/2026-10-23-nota.md',
        'contents' => "---\ntype: note\nauthor: Ana\n---\n\nOriginal.\n",
        'message' => 'Añade la nota',
    ]);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/contents/types/note.yml')) {
            return Http::response(['content' => base64_encode(Scaffold::files()['types/note.yml']), 'sha' => 't']);
        }

        if ($request->method() === 'GET') {
            return Http::response([], 404);
        }

        return Http::response([
            'content' => ['path' => 'inbox/2026-10-23-nota.md', 'sha' => 'n'],
            'commit' => ['sha' => 'cafebabe'],
        ]);
    });

    $this->actingAs($this->user)
        ->postJson(route('actions.sense', $action), ['contents' => $edited])
        ->assertOk();

    // The request middleware trims the body, including a trailing newline.
    $saved = trim($edited);

    expect($action->fresh()->status)->toBe(ActionStatus::Executed)
        ->and($action->fresh()->payload['contents'])->toBe($saved);

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['content'] === base64_encode($saved));
});

test('a rewrite that breaks the type rules is refused and nothing is committed', function () {
    $action = app(Actions::class)->propose($this->user, 'sense.write', 'Guardar nota', [
        'path' => 'inbox/2026-10-23-nota.md',
        'contents' => "---\ntype: note\nauthor: Ana\n---\n\nOriginal.\n",
        'message' => 'Añade la nota',
    ]);

    Http::fake([
        'api.github.com/*/contents/types/note.yml*' => Http::response([
            'content' => base64_encode(Scaffold::files()['types/note.yml']),
            'sha' => 't',
        ]),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('actions.sense', $action), ['contents' => "---\ntype: note\n---\n\nSin autor.\n"])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('contents');

    expect($action->fresh()->status)->toBe(ActionStatus::Pending);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
});

test('only a pending markdown proposal can be rewritten', function () {
    $action = app(Actions::class)->propose($this->user, 'sense.delete', 'Borrar nota', [
        'path' => 'inbox/2026-10-23-nota.md',
        'message' => 'Quita la nota',
    ]);

    $this->actingAs($this->user)
        ->postJson(route('actions.sense', $action), ['contents' => "---\ntype: note\nauthor: Ana\n---\n\nNo.\n"])
        ->assertUnprocessable();

    expect($action->fresh()->status)->toBe(ActionStatus::Pending);
});
