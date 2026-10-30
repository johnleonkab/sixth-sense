<?php

use App\Actions\Actions;
use App\Actions\ActionStatus;
use App\Models\GithubRepository;
use App\Models\PendingAction;
use App\Models\SenseAgent;
use App\Models\SenseSkill;
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
        name: prepago
        description: Lleva el prepago. Úsalo cuando hablen de cobros.
        ---

        Habla corto.
        MD;
    $reads = 0;

    Http::fake(function (Request $request) use (&$reads, $markdown) {
        if (str_contains($request->url(), '/git/trees/')) {
            return Http::response(['tree' => [['path' => '.claude/agents/prepago.md', 'type' => 'blob']]]);
        }

        if (str_contains($request->url(), '/contents/.claude/agents/prepago.md')) {
            if ($request->method() === 'GET' && $reads++ === 0) {
                return Http::response([], 404);
            }

            if ($request->method() === 'GET') {
                return Http::response(['content' => base64_encode($markdown), 'sha' => 'new']);
            }

            return Http::response([
                'content' => ['path' => '.claude/agents/prepago.md', 'sha' => 'new'],
                'commit' => ['sha' => 'cafebabe', 'html_url' => 'https://github.com/jhon/sense/commit/cafebabe'],
            ]);
        }

        return Http::response('unexpected', 500);
    });

    $proposal = $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'propose_sense_file'), ['arguments' => [
            'path' => '.claude/agents/prepago.md',
            'contents' => $markdown,
            'message' => 'Añade el agente de prepago',
        ]])
        ->assertJsonPath('canvas.view', 'sense.file')
        ->assertJsonPath('canvas.props.removing', false);

    $this->actingAs($this->user)->postJson(route('actions.confirm', $proposal->json('output.pending_action_id')))->assertOk();

    $agent = SenseAgent::query()->where('user_id', $this->user->id)->where('slug', 'prepago')->first();

    expect($agent)->not->toBeNull()
        ->and($agent->name)->toBe('prepago')
        ->and($agent->description)->toBe('Lleva el prepago. Úsalo cuando hablen de cobros.')
        ->and($agent->instructions)->toBe('Habla corto.');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request['message'] === 'Añade el agente de prepago'
        && ! array_key_exists('sha', $request->data()));
});

test('an agent without a description is refused, as Claude Code requires one', function () {
    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'propose_sense_file'), ['arguments' => [
            'path' => '.claude/agents/prepago.md',
            'contents' => "---\nname: prepago\n---\n\nHabla corto.\n",
            'message' => 'Añade el agente de prepago',
        ]])
        ->assertJsonPath('output.error', fn (string $error): bool => str_contains($error, 'description'));

    expect(PendingAction::query()->count())->toBe(0);
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

test('focusing an agent preloads the skills it lists', function () {
    SenseSkill::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'jira-template',
        'instructions' => 'Pilares.',
        'files' => ['.claude/skills/jira-template/plantilla.md'],
    ]);
    SenseAgent::factory()->create([
        'user_id' => $this->user->id,
        'slug' => 'jira-writer',
        'name' => 'jira-writer',
        'instructions' => 'Sigue la plantilla.',
        'skills' => ['jira-template', 'missing'],
    ]);

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'focus_sense_agent'), ['arguments' => ['name' => 'jira-writer']])
        ->assertJsonPath('output.instructions', fn (string $text): bool => str_contains($text, 'Sigue la plantilla.')
            && str_contains($text, '# Skill jira-template')
            && str_contains($text, 'Pilares.')
            && str_contains($text, '.claude/skills/jira-template/plantilla.md'));
});

test('syncing reads agents, skills and CLAUDE.md as Claude Code does', function () {
    $agent = "---\nname: jira-writer\ndescription: Escribe historias. Úsalo cuando pidan una historia.\nskills: jira-template, otra\ntools: Read, Grep\nmodel: haiku\n---\n\nSigue la plantilla.\n";

    Http::fake([
        'api.github.com/*/git/trees/*' => Http::response(['tree' => [
            ['path' => 'CLAUDE.md', 'type' => 'blob'],
            ['path' => '.claude/agents/writers/jira.md', 'type' => 'blob'],
            ['path' => '.claude/agents/broken.md', 'type' => 'blob'],
            ['path' => '.claude/skills/jira-template/SKILL.md', 'type' => 'blob'],
            ['path' => '.claude/skills/jira-template/plantilla.md', 'type' => 'blob'],
            ['path' => '.claude/skills/manual/SKILL.md', 'type' => 'blob'],
            ['path' => 'agents/old/agent.md', 'type' => 'blob'],
        ]]),
        'api.github.com/*/contents/CLAUDE.md*' => Http::response(['content' => base64_encode("# Archivo\n\nTodo en español.\n"), 'sha' => 'm']),
        'api.github.com/*/contents/.claude/agents/writers/jira.md*' => Http::response(['content' => base64_encode($agent), 'sha' => 'a']),
        'api.github.com/*/contents/.claude/agents/broken.md*' => Http::response(['content' => base64_encode("---\nname: broken\n---\n\nSin description.\n"), 'sha' => 'b']),
        'api.github.com/*/contents/.claude/skills/jira-template/SKILL.md*' => Http::response([
            'content' => base64_encode("# Plantilla de Jira\n\nCómo se escribe una historia.\n\nComo rol, quiero…\n"),
            'sha' => 's',
        ]),
        'api.github.com/*/contents/.claude/skills/manual/SKILL.md*' => Http::response([
            'content' => base64_encode("---\nname: deploy\ndescription: Despliega.\ndisable-model-invocation: true\n---\n\nPasos.\n"),
            'sha' => 'd',
        ]),
    ]);

    $this->actingAs($this->user)
        ->post(route('settings.connections.sense.sync', [$this->repository->account, $this->repository]))
        ->assertRedirect();

    $stored = $this->user->senseAgents()->get();
    $jira = $stored->firstWhere('slug', 'jira-writer');
    $template = $this->user->senseSkills()->where('name', 'jira-template')->first();
    $manual = $this->user->senseSkills()->where('name', 'deploy')->first();

    expect($stored->pluck('slug')->all())->toBe(['jira-writer'])
        ->and($jira->source_path)->toBe('.claude/agents/writers/jira.md')
        ->and($jira->description)->toBe('Escribe historias. Úsalo cuando pidan una historia.')
        ->and($jira->skills)->toBe(['jira-template', 'otra'])
        ->and($jira->model)->toBe('haiku')
        ->and($template->description)->toBe('Plantilla de Jira')
        ->and($template->files)->toBe(['.claude/skills/jira-template/plantilla.md'])
        ->and($manual->model_invocable)->toBeFalse()
        ->and($this->repository->fresh()->memory)->toBe("# Archivo\n\nTodo en español.")
        ->and($jira->briefing())->toContain('Sigue la plantilla.')
        ->and($jira->briefing())->toContain('Como rol, quiero…');
});

test('using a skill returns its SKILL.md and where its other files are', function () {
    SenseSkill::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'jira-template',
        'instructions' => 'Como rol, quiero…',
        'files' => ['.claude/skills/jira-template/plantilla.md'],
    ]);

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'use_sense_skill'), ['arguments' => ['name' => 'jira-template']])
        ->assertJsonPath('output.skill', 'jira-template')
        ->assertJsonPath('output.instructions', fn (string $text): bool => str_contains($text, 'Como rol, quiero…')
            && str_contains($text, '.claude/skills/jira-template/plantilla.md'));

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'use_sense_skill'), ['arguments' => ['name' => 'otra']])
        ->assertJsonPath('output.error', fn (string $error): bool => str_contains($error, 'otra'));
});

test('focusing an agent puts its name on screen and returns its instructions', function () {
    SenseAgent::factory()->create([
        'user_id' => $this->user->id,
        'slug' => 'prepago',
        'name' => 'Prepago',
        'instructions' => 'Habla corto.',
    ]);

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'focus_sense_agent'), ['arguments' => ['name' => 'prepago']])
        ->assertJsonPath('canvas.view', 'sense.agent')
        ->assertJsonPath('canvas.props.name', 'Prepago')
        ->assertJsonPath('output.instructions', 'Habla corto.');

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'focus_sense_agent'), ['arguments' => ['name' => '']])
        ->assertJsonPath('canvas.props.name', null);
});

test('the assistant is told which agents and skills it can use, and carries CLAUDE.md', function () {
    $this->repository->update(['memory' => 'Todo en español.']);
    SenseAgent::factory()->create([
        'user_id' => $this->user->id,
        'slug' => 'prepago',
        'name' => 'prepago',
        'description' => 'Lleva el prepago. Úsalo cuando hablen de cobros.',
    ]);
    SenseSkill::factory()->create(['user_id' => $this->user->id, 'name' => 'jira-template', 'description' => 'Plantilla de Jira.']);
    SenseSkill::factory()->create(['user_id' => $this->user->id, 'name' => 'deploy', 'model_invocable' => false]);

    $profile = AssistantProfile::for($this->user, CarbonImmutable::parse('2026-10-23 12:00', 'UTC'));

    expect($profile->instructions)->toContain('jhon/sense')
        ->and($profile->instructions)->toContain('Todo en español.')
        ->and($profile->instructions)->toContain('prepago: Lleva el prepago. Úsalo cuando hablen de cobros.')
        ->and($profile->instructions)->toContain('jira-template: Plantilla de Jira.')
        ->and($profile->instructions)->not->toContain('deploy:')
        ->and($profile->instructions)->toContain('delegate y agent archivo:<name>')
        ->and($profile->instructions)->not->toContain('focus_sense_agent');
});

test('an agent that disappeared from the repository is dropped on sync', function () {
    SenseAgent::factory()->create(['user_id' => $this->user->id, 'slug' => 'viejo']);
    SenseSkill::factory()->create(['user_id' => $this->user->id, 'name' => 'vieja']);

    Http::fake([
        'api.github.com/*/git/trees/*' => Http::response(['tree' => [
            ['path' => '.claude/agents/keeper.md', 'type' => 'blob'],
        ]]),
        'api.github.com/*/contents/.claude/agents/keeper.md*' => Http::response([
            'content' => base64_encode(Scaffold::files()['.claude/agents/keeper.md']),
            'sha' => 'k',
        ]),
    ]);

    $this->actingAs($this->user)
        ->post(route('settings.connections.sense.sync', [$this->repository->account, $this->repository]))
        ->assertRedirect();

    expect($this->user->senseAgents()->pluck('slug')->all())->toBe(['keeper'])
        ->and($this->user->senseSkills()->count())->toBe(0);
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
