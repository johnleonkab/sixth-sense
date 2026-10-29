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
        'name' => 'Cobros',
        'purpose' => 'Lleva el prepago.',
        'cue' => 'Cuando hablen de cobros.',
    ]);
    SenseAgent::factory()->create(['name' => 'De otra persona']);

    $this->actingAs($user)
        ->get(route('settings.archive.agents.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/archive-agents')
            ->where('repository.full_name', $repository->full_name)
            ->where('repository.writable', true)
            ->has('agents', 1)
            ->where('agents.0.slug', 'cobros')
            ->where('agents.0.name', 'Cobros'));
});

test('the form offers the fields declared by the agent type', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);

    Http::fake([
        'api.github.com/*/contents/types/agent.yml*' => Http::response([
            'content' => base64_encode(Scaffold::files()['types/agent.yml']),
            'sha' => 't',
        ]),
    ]);

    $this->actingAs($repository->account->user)
        ->get(route('settings.archive.agents.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/archive-agent')
            ->where('slug', null)
            ->where('fields.0.key', 'name')
            ->where('fields.0.label', 'Cómo se llama')
            ->where('fields.1.key', 'purpose')
            ->where('fields.1.label', 'Para qué existe')
            ->where('fields.2.key', 'when')
            ->where('fields.2.label', 'Cuándo usarlo'));
});

test('creating an agent commits the yaml fields and the markdown', function () {
    $repository = GithubRepository::factory()->writable()->create([
        'owner' => 'jhon',
        'name' => 'sense',
        'full_name' => 'jhon/sense',
        'is_work' => true,
    ]);
    $saved = null;

    Http::fake(function (Request $request) use (&$saved) {
        if (str_contains($request->url(), '/contents/types/agent.yml')) {
            return Http::response(['content' => base64_encode(Scaffold::files()['types/agent.yml']), 'sha' => 't']);
        }

        if (str_contains($request->url(), '/git/trees/')) {
            return Http::response(['tree' => [['path' => 'agents/cobros/agent.md', 'type' => 'blob']]]);
        }

        if (str_contains($request->url(), '/contents/agents/cobros/agent.md')) {
            if ($request->method() === 'PUT') {
                $saved = base64_decode((string) $request['content']);

                return Http::response([
                    'content' => ['path' => 'agents/cobros/agent.md', 'sha' => 'new'],
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
                'name' => 'Cobros',
                'purpose' => 'Lleva: el prepago.',
                'when' => 'Cuando hablen de cobros.',
            ],
            'body' => "# Cobros\n\nHabla corto.",
        ])
        ->assertRedirect(route('settings.archive.agents.edit', 'cobros'))
        ->assertInertiaFlash('toast.message', 'Agente creado.');

    $document = DocumentRules::document((string) $saved);

    expect($document)->not->toBeNull()
        ->and($document['name'])->toBe('Cobros')
        ->and($document['purpose'])->toBe('Lleva: el prepago.')
        ->and($document['when'])->toBe('Cuando hablen de cobros.')
        ->and($document['_body'])->toContain('# Cobros')
        ->and($document['_body'])->toContain('Habla corto.');

    $agent = $repository->account->user->senseAgents()->where('slug', 'cobros')->first();

    expect($agent)->not->toBeNull()
        ->and($agent->name)->toBe('Cobros')
        ->and($agent->cue)->toBe('Cuando hablen de cobros.')
        ->and($agent->instructions)->toContain('Habla corto.');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/repos/jhon/sense/contents/agents/cobros/agent.md')
        && $request['message'] === 'Crea el agente Cobros'
        && ! array_key_exists('sha', $request->data()));
});

test('a new agent without the fields its type requires is not written', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);

    Http::fake([
        'api.github.com/*/contents/types/agent.yml*' => Http::response([
            'content' => base64_encode(Scaffold::files()['types/agent.yml']),
            'sha' => 't',
        ]),
    ]);

    $this->actingAs($repository->account->user)
        ->post(route('settings.archive.agents.store'), [
            'slug' => 'cobros',
            'fields' => ['name' => 'Cobros', 'when' => 'Cuando hablen de cobros.'],
            'body' => 'Habla corto.',
        ])
        ->assertSessionHasErrors(['fields.purpose' => 'Falta Para qué existe.']);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    expect($repository->account->user->senseAgents()->count())->toBe(0);
});

test('the folder name only accepts lowercase letters, numbers and dashes', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);

    Http::fake([
        'api.github.com/*/contents/types/agent.yml*' => Http::response([
            'content' => base64_encode(Scaffold::files()['types/agent.yml']),
            'sha' => 't',
        ]),
    ]);

    $this->actingAs($repository->account->user)
        ->post(route('settings.archive.agents.store'), [
            'slug' => 'Cobros',
            'fields' => [
                'name' => 'Cobros',
                'purpose' => 'Lleva el prepago.',
                'when' => 'Cuando hablen de cobros.',
            ],
            'body' => 'Habla corto.',
        ])
        ->assertSessionHasErrors(['slug' => 'La carpeta solo puede llevar minúsculas, números y guiones.']);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
});

test('an agent opens with the fields from its file and its markdown', function () {
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);
    $markdown = <<<'MD'
        ---
        type: agent
        name: Cobros
        purpose: Lleva el prepago.
        when: Cuando hablen de cobros.
        ---

        Habla corto.
        MD;

    Http::fake([
        'api.github.com/*/contents/types/agent.yml*' => Http::response([
            'content' => base64_encode(Scaffold::files()['types/agent.yml']),
            'sha' => 't',
        ]),
        'api.github.com/*/contents/agents/cobros/agent.md*' => Http::response([
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
            ->where('fields.0.value', 'Cobros')
            ->where('fields.1.value', 'Lleva el prepago.')
            ->where('fields.2.value', 'Cuando hablen de cobros.')
            ->where('body', 'Habla corto.')
            ->where('readable', true));
});

test('updating an agent keeps fields the form does not edit', function () {
    $repository = GithubRepository::factory()->writable()->create([
        'owner' => 'jhon',
        'name' => 'sense',
        'full_name' => 'jhon/sense',
        'is_work' => true,
    ]);
    $original = <<<'MD'
        ---
        type: agent
        name: Cobros
        purpose: Lleva el prepago.
        when: Cuando hablen de cobros.
        tone: cercano
        ---

        Habla corto.
        MD;
    $saved = null;

    Http::fake(function (Request $request) use (&$saved, $original) {
        if (str_contains($request->url(), '/contents/types/agent.yml')) {
            return Http::response(['content' => base64_encode(Scaffold::files()['types/agent.yml']), 'sha' => 't']);
        }

        if (str_contains($request->url(), '/git/trees/')) {
            return Http::response(['tree' => [['path' => 'agents/cobros/agent.md', 'type' => 'blob']]]);
        }

        if (str_contains($request->url(), '/contents/agents/cobros/agent.md')) {
            if ($request->method() === 'PUT') {
                $saved = base64_decode((string) $request['content']);

                return Http::response([
                    'content' => ['path' => 'agents/cobros/agent.md', 'sha' => 'new'],
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
            'fields' => [
                'name' => 'Cobros',
                'purpose' => 'Lleva el prepago.',
                'when' => 'Cuando hablen de cobros.',
            ],
            'body' => "Habla corto.\n\nY cita el importe.",
        ])
        ->assertRedirect(route('settings.archive.agents.edit', 'cobros'));

    $document = DocumentRules::document((string) $saved);

    expect($document['tone'])->toBe('cercano')
        ->and($document['_body'])->toContain('Y cita el importe.');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['sha'] === 'old');
});

test('a read only archive refuses to create an agent', function () {
    $repository = GithubRepository::factory()->create(['is_work' => true]);

    Http::fake([
        'api.github.com/*/contents/types/agent.yml*' => Http::response([
            'content' => base64_encode(Scaffold::files()['types/agent.yml']),
            'sha' => 't',
        ]),
    ]);

    $this->actingAs($repository->account->user)
        ->post(route('settings.archive.agents.store'), [
            'slug' => 'cobros',
            'fields' => [
                'name' => 'Cobros',
                'purpose' => 'Lleva el prepago.',
                'when' => 'Cuando hablen de cobros.',
            ],
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
        'name' => 'Cobros',
    ]);

    Http::fake([
        'api.github.com/*/contents/agents/cobros/agent.md*' => Http::response([], 404),
        'api.github.com/*/contents/types/agent.yml*' => Http::response([
            'content' => base64_encode(Scaffold::files()['types/agent.yml']),
            'sha' => 't',
        ]),
    ]);

    $this->actingAs($repository->account->user)
        ->get(route('settings.archive.agents.edit', 'cobros'))
        ->assertNotFound();
});
