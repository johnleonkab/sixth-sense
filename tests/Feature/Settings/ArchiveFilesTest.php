<?php

use App\Models\GithubRepository;
use App\Models\SenseAgent;
use App\Models\User;
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
