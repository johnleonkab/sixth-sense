<?php

use App\Models\GithubRepository;
use App\Models\SenseAgent;
use App\Models\User;
use App\Sense\DocumentRules;
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

test('the archive section explains itself until a repository is mounted', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.archive.agents.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/archive'));

    $this->get(route('settings.archive.index'))->assertRedirect(route('settings.archive.agents.index'));
});

test('a guest cannot open the agents', function () {
    $this->get(route('settings.archive.agents.index'))->assertRedirect(route('login'));
});

test('the agents already copied from the archive are listed', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);
    $user = $repository->account->user;
    SenseAgent::factory()->create([
        'user_id' => $user->id,
        'slug' => 'cobros',
        'name' => 'cobros',
        'description' => 'Lleva el prepago. Úsalo cuando hablen de cobros.',
        'skills' => ['prepago-reglas'],
        'source_path' => '.claude/agents/cobros.md',
    ]);
    SenseAgent::factory()->create(['name' => 'de-otra-persona']);

    $this->actingAs($user)
        ->get(route('settings.archive.agents.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/archive-agents')
            ->where('repository.full_name', $repository->full_name)
            ->where('repository.writable', true)
            ->has('agents', 1)
            ->where('agents.0.slug', 'cobros')
            ->where('agents.0.description', 'Lleva el prepago. Úsalo cuando hablen de cobros.')
            ->where('agents.0.skills', ['prepago-reglas'])
            ->where('agents.0.path', '.claude/agents/cobros.md'));
});

test('the form offers the fields of a Claude Code subagent', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);

    $this->actingAs($repository->account->user)
        ->get(route('settings.archive.agents.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/archive-agent')
            ->where('slug', null)
            ->where('fields.0.key', 'description')
            ->where('fields.0.required', true)
            ->where('fields.1.key', 'skills')
            ->where('fields.1.required', false));
});

test('creating an agent commits a Claude Code subagent', function () {
    $repository = GithubRepository::factory()->writable()->create([
        'owner' => 'jhon',
        'name' => 'sense',
        'full_name' => 'jhon/sense',
        'is_work' => true,
    ]);
    $saved = null;

    Http::fake(function (Request $request) use (&$saved) {
        if (str_contains($request->url(), '/git/trees/')) {
            return Http::response(['tree' => [['path' => '.claude/agents/cobros.md', 'type' => 'blob']]]);
        }

        if (str_contains($request->url(), '/contents/.claude/agents/cobros.md')) {
            if ($request->method() === 'PUT') {
                $saved = base64_decode((string) $request['content']);

                return Http::response([
                    'content' => ['path' => '.claude/agents/cobros.md', 'sha' => 'new'],
                    'commit' => ['sha' => 'c', 'html_url' => 'https://github.com/jhon/sense/commit/c'],
                ]);
            }

            if (! is_string($saved)) {
                return Http::response([], 404);
            }

            return Http::response(['content' => base64_encode($saved), 'sha' => 'new']);
        }

        return null;
    });

    $this->actingAs($repository->account->user)
        ->post(route('settings.archive.agents.store'), [
            'slug' => 'cobros',
            'fields' => [
                'description' => 'Lleva: el prepago.',
                'skills' => 'prepago-reglas, tono',
            ],
            'body' => "# Cobros\n\nHabla corto.",
        ])
        ->assertRedirect(route('settings.archive.agents.edit', 'cobros'))
        ->assertInertiaFlash('toast.message', 'Agente creado.');

    $document = DocumentRules::document((string) $saved);

    expect($document)->not->toBeNull()
        ->and($document['name'])->toBe('cobros')
        ->and($document['description'])->toBe('Lleva: el prepago.')
        ->and($document['skills'])->toBe(['prepago-reglas', 'tono'])
        ->and($document)->not->toHaveKey('type')
        ->and($document['_body'])->toContain('Habla corto.');

    $agent = $repository->account->user->senseAgents()->where('slug', 'cobros')->first();

    expect($agent)->not->toBeNull()
        ->and($agent->description)->toBe('Lleva: el prepago.')
        ->and($agent->skills)->toBe(['prepago-reglas', 'tono'])
        ->and($agent->instructions)->toContain('Habla corto.');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/repos/jhon/sense/contents/.claude/agents/cobros.md')
        && $request['message'] === 'Crea el agente cobros'
        && ! array_key_exists('sha', $request->data()));
});

test('a new agent without a description is not written', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);

    $this->actingAs($repository->account->user)
        ->post(route('settings.archive.agents.store'), [
            'slug' => 'cobros',
            'fields' => ['skills' => ''],
            'body' => 'Habla corto.',
        ])
        ->assertSessionHasErrors(['fields.description' => 'Falta Cuándo usarlo.']);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    expect($repository->account->user->senseAgents()->count())->toBe(0);
});

test('the name only accepts lowercase letters, numbers and dashes', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);

    $this->actingAs($repository->account->user)
        ->post(route('settings.archive.agents.store'), [
            'slug' => 'Cobros',
            'fields' => ['description' => 'Lleva el prepago.'],
            'body' => 'Habla corto.',
        ])
        ->assertSessionHasErrors(['slug' => 'El nombre solo puede llevar minúsculas, números y guiones.']);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
});

test('an agent opens from the file it was synced from', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);
    SenseAgent::factory()->create([
        'user_id' => $repository->account->user->id,
        'slug' => 'cobros',
        'name' => 'cobros',
        'source_path' => '.claude/agents/finanzas/cobros.md',
    ]);
    $markdown = <<<'MD'
        ---
        name: cobros
        description: Lleva el prepago.
        skills: [prepago-reglas]
        ---

        Habla corto.
        MD;

    Http::fake([
        'api.github.com/*/contents/.claude/agents/finanzas/cobros.md*' => Http::response([
            'content' => base64_encode($markdown),
            'sha' => 'old',
        ]),
    ]);

    $this->actingAs($repository->account->user)
        ->get(route('settings.archive.agents.edit', 'cobros'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/archive-agent')
            ->where('slug', 'cobros')
            ->where('path', '.claude/agents/finanzas/cobros.md')
            ->where('fields.0.value', 'Lleva el prepago.')
            ->where('fields.1.value', 'prepago-reglas')
            ->where('body', 'Habla corto.')
            ->where('readable', true));
});

test('updating an agent keeps the front matter the form does not edit', function () {
    $repository = GithubRepository::factory()->writable()->create([
        'owner' => 'jhon',
        'name' => 'sense',
        'full_name' => 'jhon/sense',
        'is_work' => true,
    ]);
    SenseAgent::factory()->create([
        'user_id' => $repository->account->user->id,
        'slug' => 'cobros',
        'name' => 'cobros',
        'source_path' => '.claude/agents/cobros.md',
    ]);
    $original = <<<'MD'
        ---
        name: cobros
        description: Lleva el prepago.
        tools: Read, Grep
        model: sonnet
        ---

        Habla corto.
        MD;
    $saved = null;

    Http::fake(function (Request $request) use (&$saved, $original) {
        if (str_contains($request->url(), '/git/trees/')) {
            return Http::response(['tree' => [['path' => '.claude/agents/cobros.md', 'type' => 'blob']]]);
        }

        if (str_contains($request->url(), '/contents/.claude/agents/cobros.md')) {
            if ($request->method() === 'PUT') {
                $saved = base64_decode((string) $request['content']);

                return Http::response([
                    'content' => ['path' => '.claude/agents/cobros.md', 'sha' => 'new'],
                    'commit' => ['sha' => 'c', 'html_url' => 'https://github.com/jhon/sense/commit/c'],
                ]);
            }

            $content = is_string($saved) ? $saved : $original;

            return Http::response(['content' => base64_encode($content), 'sha' => 'old']);
        }

        return null;
    });

    $this->actingAs($repository->account->user)
        ->put(route('settings.archive.agents.update', 'cobros'), [
            'fields' => ['description' => 'Lleva el prepago.', 'skills' => ''],
            'body' => "Habla corto.\n\nY cita el importe.",
        ])
        ->assertRedirect(route('settings.archive.agents.edit', 'cobros'));

    $document = DocumentRules::document((string) $saved);

    expect($document['tools'])->toBe('Read, Grep')
        ->and($document['model'])->toBe('sonnet')
        ->and($document['_body'])->toContain('Y cita el importe.');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['sha'] === 'old');
});

test('a read only archive refuses to create an agent', function () {
    $repository = GithubRepository::factory()->create(['is_work' => true]);

    $this->actingAs($repository->account->user)
        ->post(route('settings.archive.agents.store'), [
            'slug' => 'cobros',
            'fields' => ['description' => 'Lleva el prepago.'],
            'body' => 'Habla corto.',
        ])
        ->assertSessionHasErrors(['agent' => 'Este archivo es de solo lectura. Permite actualizar su código para guardar agentes.']);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
});

test('another persons agent is not readable from this archive', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);
    $other = GithubRepository::factory()->writable()->create(['is_work' => true]);
    SenseAgent::factory()->create([
        'user_id' => $other->account->user->id,
        'slug' => 'cobros',
        'name' => 'cobros',
    ]);

    $this->actingAs($repository->account->user)
        ->get(route('settings.archive.agents.edit', 'cobros'))
        ->assertNotFound();
});
