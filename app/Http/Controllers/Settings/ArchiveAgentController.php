<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\GithubRepository;
use App\Models\SenseAgent;
use App\Models\User;
use App\Sense\AgentDocument;
use App\Sense\SyncAgents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The work archive's agents, edited by hand. Each one is a Claude Code
 * subagent in .claude/agents/, written back to the repository.
 */
class ArchiveAgentController extends Controller
{
    public function __construct(
        private AgentDocument $documents,
        private SyncAgents $agents,
    ) {}

    /**
     * Agents already copied from the archive. Without an archive, the page
     * only explains how to set one up.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $repository = $user->workRepository();

        if ($repository === null) {
            return Inertia::render('settings/archive', [
                'repository' => null,
                'agents' => [],
            ]);
        }

        return Inertia::render('settings/archive-agents', [
            'repository' => $this->repository($repository),
            'agents' => $user->senseAgents()->orderBy('name')->get()->map(fn (SenseAgent $agent): array => [
                'slug' => $agent->slug,
                'name' => $agent->name,
                'description' => $agent->description,
                'skills' => $agent->skills ?? [],
                'path' => $agent->source_path,
            ])->values(),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $repository = $this->archive($request->user());

        if ($repository instanceof RedirectResponse) {
            return $repository;
        }

        return Inertia::render('settings/archive-agent', [
            'repository' => $this->repository($repository),
            'slug' => null,
            'path' => null,
            'fields' => array_map(fn (array $field): array => [...$field, 'value' => ''], $this->documents->definition()),
            'body' => '',
            'readable' => true,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $repository = $this->archive($request->user());

        if ($repository instanceof RedirectResponse) {
            return $repository;
        }

        $validated = $request->validate($this->rules(true), $this->messages());

        return $this->save($request->user(), $repository, $validated['slug'], null, $validated['fields'], (string) ($validated['body'] ?? ''));
    }

    public function edit(Request $request, string $slug): Response|RedirectResponse
    {
        $repository = $this->archive($request->user());

        if ($repository instanceof RedirectResponse) {
            return $repository;
        }

        $stored = $this->stored($request->user(), $slug);

        try {
            $agent = $this->documents->open($repository, $stored->source_path);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('settings.archive.agents.index')
                ->withErrors(['agent' => 'No he podido leer ese agente.']);
        }

        if ($agent === null) {
            abort(404);
        }

        return Inertia::render('settings/archive-agent', [
            'repository' => $this->repository($repository),
            'slug' => $slug,
            'path' => $stored->source_path,
            'fields' => array_map(fn (array $field): array => [
                ...$field,
                'value' => $agent['fields'][$field['key']] ?? '',
            ], $this->documents->definition()),
            'body' => $agent['body'],
            'readable' => $agent['readable'],
        ]);
    }

    public function update(Request $request, string $slug): RedirectResponse
    {
        $repository = $this->archive($request->user());

        if ($repository instanceof RedirectResponse) {
            return $repository;
        }

        $stored = $this->stored($request->user(), $slug);
        $validated = $request->validate($this->rules(false), $this->messages());

        return $this->save($request->user(), $repository, $stored->name, $stored->source_path, $validated['fields'], (string) ($validated['body'] ?? ''));
    }

    /**
     * Copy the repository's agents and skills into sixth-sense again.
     */
    public function sync(Request $request): RedirectResponse
    {
        $repository = $this->archive($request->user());

        if ($repository instanceof RedirectResponse) {
            return $repository;
        }

        try {
            $count = $this->agents->sync($request->user());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['agent' => 'No he podido leer los agentes del archivo.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Agentes en el archivo: {$count}."]);

        return back();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function save(User $user, GithubRepository $repository, string $name, ?string $path, array $fields, string $body): RedirectResponse
    {
        $creating = $path === null;

        try {
            $this->documents->write($repository, $name, $path, $fields, $body);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['agent' => 'No he podido guardar el agente en GitHub.'])->withInput();
        }

        try {
            $this->agents->sync($user);
        } catch (Throwable $exception) {
            report($exception);

            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => 'Guardado en el archivo, pero no he podido actualizar la lista.',
            ]);

            return redirect()->route('settings.archive.agents.index');
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $creating ? 'Agente creado.' : 'Agente actualizado.',
        ]);

        return redirect()->route('settings.archive.agents.edit', $name);
    }

    private function stored(User $user, string $slug): SenseAgent
    {
        return $user->senseAgents()->where('slug', $slug)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        $rules = [
            'body' => ['nullable', 'string', 'max:20000'],
            'fields' => ['required', 'array'],
        ];

        foreach ($this->documents->definition() as $field) {
            $rules['fields.'.$field['key']] = [$field['required'] ? 'required' : 'nullable', 'string', 'max:2000'];
        }

        if ($creating) {
            $rules['slug'] = ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        $messages = [
            'slug.required' => 'Escribe el nombre del agente.',
            'slug.regex' => 'El nombre solo puede llevar minúsculas, números y guiones.',
            'slug.max' => 'El nombre es demasiado largo.',
            'body.max' => 'El markdown es demasiado largo.',
        ];

        foreach ($this->documents->definition() as $field) {
            $messages['fields.'.$field['key'].'.required'] = "Falta {$field['label']}.";
            $messages['fields.'.$field['key'].'.max'] = "{$field['label']} es demasiado largo.";
        }

        return $messages;
    }

    /**
     * @return array{full_name: string, writable: bool}
     */
    private function repository(GithubRepository $repository): array
    {
        return [
            'full_name' => $repository->full_name,
            'writable' => $repository->mayUpdateCode(),
        ];
    }

    private function archive(User $user): GithubRepository|RedirectResponse
    {
        return $user->workRepository() ?? redirect()->route('settings.archive.agents.index');
    }
}
