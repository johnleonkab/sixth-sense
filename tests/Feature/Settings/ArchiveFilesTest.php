<?php

use App\Models\GithubRepository;
use App\Models\SenseAgent;
use App\Models\User;
use App\Sense\DocumentRules;
use App\Sense\Scaffold;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::allowStrayRequests([
        'http://[::1]:5175/*',
        'http://127.0.0.1:5175/*',
        'http://localhost:5175/*',
    ]);
});

test('files stay hidden until an archive is mounted', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.archive.files.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/archive'));
});

test('a guest cannot open the documents', function () {
    $this->get(route('settings.archive.files.index'))->assertRedirect(route('login'));
});

test('the list is the markdown on main', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);

    Http::fake([
        'api.github.com/*/git/trees/main*' => Http::response(['tree' => [
            ['path' => 'types/agent.yml', 'type' => 'blob'],
            ['path' => 'inbox/.gitkeep', 'type' => 'blob'],
            ['path' => 'now.md', 'type' => 'blob'],
            ['path' => 'agents/keeper/agent.md', 'type' => 'blob'],
            ['path' => 'projects/prepago/project.md', 'type' => 'blob'],
        ]]),
    ]);

    $this->actingAs($repository->account->user)
        ->get(route('settings.archive.files.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/archive-files')
            ->where('repository.writable', true)
            ->where('files', [
                'agents/keeper/agent.md',
                'now.md',
                'projects/prepago/project.md',
            ]));
});

test('a document opens with its markdown', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);
    $markdown = "# Ahora\n\nEl prepago.\n";

    Http::fake([
        'api.github.com/*/git/trees/main*' => Http::response(['tree' => [
            ['path' => 'now.md', 'type' => 'blob'],
            ['path' => 'agents/keeper/agent.md', 'type' => 'blob'],
        ]]),
        'api.github.com/*/contents/now.md*' => Http::response([
            'content' => base64_encode($markdown),
            'sha' => 'old',
        ]),
    ]);

    $this->actingAs($repository->account->user)
        ->get('/settings/archive/files/now.md')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/archive-files')
            ->where('document.path', 'now.md')
            ->where('document.branch', 'main')
            ->where('document.contents', $markdown)
            ->where('files', ['agents/keeper/agent.md', 'now.md']));
});

test('saving commits the markdown onto main and does not open a branch', function () {
    $repository = GithubRepository::factory()->writable()->create([
        'owner' => 'jhon',
        'name' => 'sense',
        'full_name' => 'jhon/sense',
        'default_branch' => 'main',
        'is_work' => true,
    ]);
    $saved = null;

    Http::fake(function (Request $request) use (&$saved) {
        if (! str_contains($request->url(), 'api.github.com')) {
            return null;
        }

        if (str_contains($request->url(), '/contents/now.md')) {
            if ($request->method() === 'PUT') {
                $saved = base64_decode((string) $request['content']);

                return Http::response([
                    'content' => ['path' => 'now.md', 'sha' => 'new'],
                    'commit' => ['sha' => 'c', 'html_url' => 'https://github.com/jhon/sense/commit/c'],
                ]);
            }

            return Http::response(['content' => base64_encode("# Ahora\n"), 'sha' => 'old']);
        }

        return Http::response('unexpected '.$request->url(), 500);
    });

    $this->actingAs($repository->account->user)
        ->put('/settings/archive/files/now.md', ['contents' => "# Ahora\n\nEl prepago."])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Guardado en main.');

    expect($saved)->toBe("# Ahora\n\nEl prepago.");

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/repos/jhon/sense/contents/now.md')
        && $request['branch'] === 'main'
        && $request['sha'] === 'old'
        && $request['message'] === 'Actualiza now.md');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/pulls') || str_contains($request->url(), '/git/refs'));
});

test('saving without the document is refused', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);

    $this->actingAs($repository->account->user)
        ->put('/settings/archive/files/now.md', [])
        ->assertSessionHasErrors(['contents' => 'Falta el documento.']);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.github.com'));
});

test('a document that breaks its type is not committed', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);
    $path = 'projects/prepago/docs/2026-10-23-brief.md';

    Http::fake([
        'api.github.com/*/contents/projects/prepago/docs/2026-10-23-brief.md*' => Http::response([
            'content' => base64_encode("antes\n"),
            'sha' => 'old',
        ]),
        'api.github.com/*/contents/types/prd.yml*' => Http::response([
            'content' => base64_encode(Scaffold::files()['types/prd.yml']),
            'sha' => 't',
        ]),
    ]);

    $this->actingAs($repository->account->user)
        ->put('/settings/archive/files/'.$path, [
            'contents' => "---\ntype: prd\nauthor: Ana\nwhy: Porque el cobro falla.\n---\n\n# Cobro\n",
        ])
        ->assertSessionHasErrors(['contents' => 'Falta outcome (Qué se espera al terminar).']);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
});

test('saving does not create a document that is not already on main', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);

    Http::fake([
        'api.github.com/*/contents/now.md*' => Http::response([], 404),
    ]);

    $this->actingAs($repository->account->user)
        ->put('/settings/archive/files/now.md', ['contents' => "# Ahora\n"])
        ->assertSessionHasErrors(['contents' => 'Ese documento no está en main.']);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
});

test('a read only archive refuses to commit', function () {
    $repository = GithubRepository::factory()->create(['is_work' => true]);

    $this->actingAs($repository->account->user)
        ->put('/settings/archive/files/now.md', ['contents' => "# Ahora\n"])
        ->assertSessionHasErrors(['contents' => 'Este archivo es de solo lectura. Permite actualizar su código para guardar en main.']);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.github.com'));
});

test('a path that is not markdown is refused', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);

    $this->actingAs($repository->account->user)
        ->get('/settings/archive/files/types/agent.yml')
        ->assertNotFound();

    $this->put('/settings/archive/files/../secret.md', ['contents' => 'no'])->assertNotFound();
});

test('another persons document is not read from this archive', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true, 'owner' => 'jhon', 'name' => 'sense']);
    $other = GithubRepository::factory()->writable()->create(['is_work' => true, 'owner' => 'otra', 'name' => 'sense']);

    Http::fake(function (Request $request) {
        if (! str_contains($request->url(), 'api.github.com')) {
            return null;
        }

        if (str_contains($request->url(), '/repos/otra/')) {
            return Http::response(['content' => base64_encode("# secreto\n"), 'sha' => 'x']);
        }

        return Http::response([], 404);
    });

    $this->actingAs($repository->account->user)
        ->get('/settings/archive/files/now.md')
        ->assertNotFound();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/repos/'.$other->owner.'/'));
});

test('saving an agent file copies it into sixth-sense', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);
    $user = $repository->account->user;
    $markdown = <<<'MD'
        ---
        type: agent
        name: Archivo
        purpose: Mantiene el archivo.
        when: Cuando haya que guardar algo.
        ---

        Nueva instrucción.
        MD;
    $saved = null;

    Http::fake(function (Request $request) use (&$saved) {
        if (! str_contains($request->url(), 'api.github.com')) {
            return null;
        }

        if (str_contains($request->url(), '/contents/types/agent.yml')) {
            return Http::response(['content' => base64_encode(Scaffold::files()['types/agent.yml']), 'sha' => 't']);
        }

        if (str_contains($request->url(), '/git/trees/main')) {
            return Http::response(['tree' => [['path' => 'agents/keeper/agent.md', 'type' => 'blob']]]);
        }

        if (str_contains($request->url(), '/contents/agents/keeper/agent.md')) {
            if ($request->method() === 'PUT') {
                $saved = base64_decode((string) $request['content']);

                return Http::response([
                    'content' => ['path' => 'agents/keeper/agent.md', 'sha' => 'new'],
                    'commit' => ['sha' => 'c', 'html_url' => 'https://github.com/jhon/sense/commit/c'],
                ]);
            }

            $content = is_string($saved) ? $saved : "---\ntype: agent\nname: Archivo\npurpose: Antes.\nwhen: Antes.\n---\n\nViejo.\n";

            return Http::response(['content' => base64_encode($content), 'sha' => 'old']);
        }

        return Http::response('unexpected', 500);
    });

    $this->actingAs($user)
        ->put('/settings/archive/files/agents/keeper/agent.md', ['contents' => $markdown])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Guardado en main.');

    $agent = SenseAgent::query()->where('user_id', $user->id)->where('slug', 'keeper')->first();

    expect($agent)->not->toBeNull()
        ->and($agent->name)->toBe('Archivo')
        ->and($agent->instructions)->toBe('Nueva instrucción.');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['branch'] === 'main');
});

test('the create page offers the types on main', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);

    Http::fake([
        'api.github.com/*/git/trees/main*' => Http::response(['tree' => [
            ['path' => 'types/note.yml', 'type' => 'blob'],
            ['path' => 'now.md', 'type' => 'blob'],
        ]]),
        'api.github.com/*/contents/types/note.yml*' => Http::response([
            'content' => base64_encode(Scaffold::files()['types/note.yml']),
            'sha' => 't',
        ]),
    ]);

    $this->actingAs($repository->account->user)
        ->get(route('settings.archive.files.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/archive-files')
            ->where('creating', true)
            ->where('types', [[
                'type' => 'note',
                'where' => 'inbox',
                'name' => '{date}-{slug}.md',
                'required' => [['key' => 'author', 'label' => 'Quién lo anota']],
                'optional' => [],
                'slots' => ['slug', 'date'],
            ]]));
});

test('a guest cannot create a document', function () {
    $this->get(route('settings.archive.files.create'))->assertRedirect(route('login'));
    $this->post(route('settings.archive.files.store'), ['kind' => 'file'])->assertRedirect(route('login'));
});

test('creating a document commits it on main at the path its type requires', function () {
    $repository = GithubRepository::factory()->writable()->create([
        'owner' => 'jhon',
        'name' => 'sense',
        'full_name' => 'jhon/sense',
        'is_work' => true,
    ]);
    $saved = null;
    $path = 'projects/prepago/docs/2026-10-24-cobro.md';

    Http::fake(function (Request $request) use (&$saved, $path) {
        if (! str_contains($request->url(), 'api.github.com')) {
            return null;
        }

        if (str_contains($request->url(), '/contents/types/prd.yml')) {
            return Http::response(['content' => base64_encode(Scaffold::files()['types/prd.yml']), 'sha' => 't']);
        }

        if (str_contains($request->url(), '/contents/'.$path)) {
            if ($request->method() === 'PUT') {
                $saved = base64_decode((string) $request['content']);

                return Http::response([
                    'content' => ['path' => $path, 'sha' => 'new'],
                    'commit' => ['sha' => 'c', 'html_url' => 'https://github.com/jhon/sense/commit/c'],
                ]);
            }

            return Http::response([], 404);
        }

        return Http::response('unexpected '.$request->url(), 500);
    });

    $this->actingAs($repository->account->user)
        ->post(route('settings.archive.files.store'), [
            'kind' => 'file',
            'type' => 'prd',
            'project' => 'prepago',
            'date' => '2026-10-24',
            'slug' => 'cobro',
            'fields' => [
                'author' => 'Ana',
                'why' => 'Porque el cobro falla.',
                'outcome' => 'El cobro queda registrado.',
            ],
            'body' => "# Cobro\n",
        ])
        ->assertRedirect('/settings/archive/files/'.$path)
        ->assertInertiaFlash('toast.message', 'Creado en main.');

    $document = DocumentRules::document((string) $saved);

    expect($document)->not->toBeNull()
        ->and($document['type'])->toBe('prd')
        ->and($document['author'])->toBe('Ana')
        ->and($document['outcome'])->toBe('El cobro queda registrado.')
        ->and(trim($document['_body']))->toBe('# Cobro');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/repos/jhon/sense/contents/'.$path)
        && $request['branch'] === 'main'
        && $request['message'] === "Crea {$path}"
        && ! array_key_exists('sha', $request->data()));

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/pulls') || str_contains($request->url(), '/git/refs'));
});

test('a document that skips a required field is not created', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);

    Http::fake([
        'api.github.com/*/contents/types/prd.yml*' => Http::response([
            'content' => base64_encode(Scaffold::files()['types/prd.yml']),
            'sha' => 't',
        ]),
        'api.github.com/*/contents/projects/prepago/docs/2026-10-24-cobro.md*' => Http::response([], 404),
    ]);

    $this->actingAs($repository->account->user)
        ->post(route('settings.archive.files.store'), [
            'kind' => 'file',
            'type' => 'prd',
            'project' => 'prepago',
            'date' => '2026-10-24',
            'slug' => 'cobro',
            'fields' => ['author' => 'Ana', 'why' => 'Porque el cobro falla.'],
            'body' => '# Cobro',
        ])
        ->assertSessionHasErrors(['contents' => 'Falta outcome (Qué se espera al terminar).']);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
});

test('creating does not replace a document that is already on main', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);

    Http::fake([
        'api.github.com/*/contents/types/note.yml*' => Http::response([
            'content' => base64_encode(Scaffold::files()['types/note.yml']),
            'sha' => 't',
        ]),
        'api.github.com/*/contents/inbox/2026-10-24-cobro.md*' => Http::response([
            'content' => base64_encode("ya está\n"),
            'sha' => 'old',
        ]),
    ]);

    $this->actingAs($repository->account->user)
        ->post(route('settings.archive.files.store'), [
            'kind' => 'file',
            'type' => 'note',
            'date' => '2026-10-24',
            'slug' => 'cobro',
            'fields' => ['author' => 'Ana'],
            'body' => '# Nota',
        ])
        ->assertSessionHasErrors(['contents' => 'Ese documento ya está en main.']);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
});

test('a project folder copies the template markdown onto main', function () {
    $repository = GithubRepository::factory()->writable()->create([
        'owner' => 'jhon',
        'name' => 'sense',
        'full_name' => 'jhon/sense',
        'is_work' => true,
    ]);
    $written = [];

    Http::fake(function (Request $request) use (&$written) {
        if (! str_contains($request->url(), 'api.github.com')) {
            return null;
        }

        if (str_contains($request->url(), '/git/trees/main')) {
            return Http::response(['tree' => [
                ['path' => 'projects/_template/project.md', 'type' => 'blob'],
                ['path' => 'projects/_template/decisions.md', 'type' => 'blob'],
                ['path' => 'types/project.yml', 'type' => 'blob'],
                ['path' => 'types/decision.yml', 'type' => 'blob'],
            ]]);
        }

        foreach (['projects/_template/project.md', 'projects/_template/decisions.md', 'types/project.yml', 'types/decision.yml'] as $path) {
            if (str_contains($request->url(), '/contents/'.$path) && $request->method() === 'GET') {
                return Http::response(['content' => base64_encode(Scaffold::files()[$path]), 'sha' => 't']);
            }
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/contents/projects/prepago/')) {
            return Http::response([], 404);
        }

        if ($request->method() === 'PUT' && str_contains($request->url(), '/contents/projects/prepago/')) {
            $written[] = str_replace('https://api.github.com/repos/jhon/sense/contents/', '', strtok($request->url(), '?'));

            return Http::response([
                'content' => ['path' => 'projects/prepago/project.md', 'sha' => 'new'],
                'commit' => ['sha' => 'c', 'html_url' => 'https://github.com/jhon/sense/commit/c'],
            ]);
        }

        return Http::response('unexpected '.$request->url(), 500);
    });

    $this->actingAs($repository->account->user)
        ->post(route('settings.archive.files.store'), [
            'kind' => 'folder',
            'folder' => 'project',
            'name' => 'prepago',
        ])
        ->assertRedirect('/settings/archive/files/projects/prepago/project.md')
        ->assertInertiaFlash('toast.message', 'Creado en main.');

    expect($written)->toEqualCanonicalizing([
        'projects/prepago/project.md',
        'projects/prepago/decisions.md',
    ]);

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['branch'] === 'main');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/pulls') || str_contains($request->url(), '/git/refs'));
});

test('an agent folder copies the template and becomes an agent', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);
    $user = $repository->account->user;
    $created = false;

    Http::fake(function (Request $request) use (&$created) {
        if (! str_contains($request->url(), 'api.github.com')) {
            return null;
        }

        if (str_contains($request->url(), '/git/trees/main')) {
            $tree = [['path' => 'agents/_template/agent.md', 'type' => 'blob']];

            if ($created) {
                $tree[] = ['path' => 'agents/cobros/agent.md', 'type' => 'blob'];
            }

            return Http::response(['tree' => $tree]);
        }

        if (str_contains($request->url(), '/contents/agents/_template/agent.md')) {
            return Http::response(['content' => base64_encode(Scaffold::files()['agents/_template/agent.md']), 'sha' => 't']);
        }

        if (str_contains($request->url(), '/contents/types/agent.yml')) {
            return Http::response(['content' => base64_encode(Scaffold::files()['types/agent.yml']), 'sha' => 't']);
        }

        if (str_contains($request->url(), '/contents/agents/cobros/agent.md')) {
            if ($request->method() === 'PUT') {
                $created = true;

                return Http::response([
                    'content' => ['path' => 'agents/cobros/agent.md', 'sha' => 'new'],
                    'commit' => ['sha' => 'c', 'html_url' => 'https://github.com/jhon/sense/commit/c'],
                ]);
            }

            if ($created) {
                return Http::response(['content' => base64_encode(Scaffold::files()['agents/_template/agent.md']), 'sha' => 'new']);
            }

            return Http::response([], 404);
        }

        return Http::response('unexpected '.$request->url(), 500);
    });

    $this->actingAs($user)
        ->post(route('settings.archive.files.store'), [
            'kind' => 'folder',
            'folder' => 'agent',
            'name' => 'cobros',
        ])
        ->assertRedirect('/settings/archive/files/agents/cobros/agent.md');

    $agent = SenseAgent::query()->where('user_id', $user->id)->where('slug', 'cobros')->first();

    expect($agent)->not->toBeNull()
        ->and($agent->name)->toBe('Nombre');
});

test('a folder name outside the archive rules is refused', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);

    $this->actingAs($repository->account->user)
        ->post(route('settings.archive.files.store'), [
            'kind' => 'folder',
            'folder' => 'project',
            'name' => 'Prepago',
        ])
        ->assertSessionHasErrors('name');

    $this->post(route('settings.archive.files.store'), [
        'kind' => 'folder',
        'folder' => 'project',
        'name' => '_template',
    ])->assertSessionHasErrors('name');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.github.com'));
});

test('a reference is committed under the agent and loaded with it', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);
    $user = $repository->account->user;
    $agent = "---\ntype: agent\nname: Jira\npurpose: Escribe historias.\nwhen: Cuando pidan una historia.\n---\n\nSigue la plantilla.\n";
    $created = false;
    $saved = null;

    Http::fake(function (Request $request) use (&$created, &$saved, $agent) {
        if (! str_contains($request->url(), 'api.github.com')) {
            return null;
        }

        if (str_contains($request->url(), '/git/trees/main')) {
            $tree = [['path' => 'agents/jira-writer/agent.md', 'type' => 'blob']];

            if ($created) {
                $tree[] = ['path' => 'agents/jira-writer/references/contexto.md', 'type' => 'blob'];
            }

            return Http::response(['tree' => $tree]);
        }

        if (str_contains($request->url(), '/contents/agents/jira-writer/agent.md')) {
            return Http::response(['content' => base64_encode($agent), 'sha' => 'a']);
        }

        if (str_contains($request->url(), '/contents/agents/jira-writer/references/contexto.md')) {
            if ($request->method() === 'PUT') {
                $created = true;
                $saved = base64_decode((string) $request['content']);

                return Http::response([
                    'content' => ['path' => 'agents/jira-writer/references/contexto.md', 'sha' => 'new'],
                    'commit' => ['sha' => 'c', 'html_url' => 'https://github.com/jhon/sense/commit/c'],
                ]);
            }

            if ($created) {
                return Http::response(['content' => base64_encode((string) $saved), 'sha' => 'new']);
            }

            return Http::response([], 404);
        }

        return Http::response('unexpected '.$request->url(), 500);
    });

    $this->actingAs($user)
        ->post(route('settings.archive.files.store'), [
            'kind' => 'reference',
            'agent' => 'jira-writer',
            'name' => 'contexto',
            'body' => "# Contexto\n\nPilares.\n",
        ])
        ->assertRedirect('/settings/archive/files/agents/jira-writer/references/contexto.md')
        ->assertInertiaFlash('toast.message', 'Creado en main.');

    $stored = $user->senseAgents()->where('slug', 'jira-writer')->first();

    expect($saved)->toBe("# Contexto\n\nPilares.")
        ->and($stored)->not->toBeNull()
        ->and($stored->instructions)->toBe('Sigue la plantilla.')
        ->and($stored->briefing())->toContain('Pilares.');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request['branch'] === 'main'
        && $request['message'] === 'Crea agents/jira-writer/references/contexto.md');
});

test('a reference for an agent that is not on main is refused', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);

    Http::fake([
        'api.github.com/*/contents/agents/jira-writer/agent.md*' => Http::response([], 404),
    ]);

    $this->actingAs($repository->account->user)
        ->post(route('settings.archive.files.store'), [
            'kind' => 'reference',
            'agent' => 'jira-writer',
            'name' => 'contexto',
            'body' => "# Contexto\n",
        ])
        ->assertSessionHasErrors(['agent' => 'No hay un agente con esa carpeta.']);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
});

test('a read only archive cannot create a document or a folder', function () {
    $repository = GithubRepository::factory()->create(['is_work' => true]);

    $this->actingAs($repository->account->user)
        ->post(route('settings.archive.files.store'), [
            'kind' => 'file',
            'type' => 'note',
            'date' => '2026-10-24',
            'slug' => 'cobro',
            'fields' => ['author' => 'Ana'],
            'body' => '# Nota',
        ])
        ->assertSessionHasErrors(['contents' => 'Este archivo es de solo lectura. Permite actualizar su código para guardar en main.']);

    $this->post(route('settings.archive.files.store'), [
        'kind' => 'folder',
        'folder' => 'project',
        'name' => 'prepago',
    ])->assertSessionHasErrors(['contents' => 'Este archivo es de solo lectura. Permite actualizar su código para guardar en main.']);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.github.com'));
});
