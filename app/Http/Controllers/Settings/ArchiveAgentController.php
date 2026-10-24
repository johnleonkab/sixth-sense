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
 * The work archive's agents, edited by hand. The form follows types/agent.yml
 * and writes agents/{slug}/agent.md back to the repository.
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
                'purpose' => $agent->purpose,
                'cue' => $agent->cue,
            ])->values(),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $repository = $this->archive($request->user());

        if ($repository instanceof RedirectResponse) {
            return $repository;
        }

        $definition = $this->definition($repository);

        if ($definition instanceof RedirectResponse) {
            return $definition;
        }

        return Inertia::render('settings/archive-agent', [
            'repository' => $this->repository($repository),
            'slug' => null,
            'fields' => array_map(fn (array $field): array => [...$field, 'value' => ''], $definition),
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

        $definition = $this->definition($repository);

        if ($definition instanceof RedirectResponse) {
            return $definition;
        }

        $validated = $request->validate($this->rules($definition, true), $this->messages($definition));

        return $this->save($request->user(), $repository, $validated['slug'], $validated['fields'], (string) ($validated['body'] ?? ''), true);
    }

    public function edit(Request $request, string $slug): Response|RedirectResponse
    {
        $repository = $this->archive($request->user());

        if ($repository instanceof RedirectResponse) {
            return $repository;
        }

        try {
            $agent = $this->documents->open($repository, $slug);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('settings.archive.agents.index')
                ->withErrors(['agent' => 'No he podido leer ese agente.']);
        }

        if ($agent === null) {
            abort(404);
        }

        $definition = $this->definition($repository);

        if ($definition instanceof RedirectResponse) {
            return $definition;
        }

        return Inertia::render('settings/archive-agent', [
            'repository' => $this->repository($repository),
            'slug' => $slug,
            'fields' => array_map(fn (array $field): array => [
                ...$field,
                'value' => $agent['fields'][$field['key']] ?? '',
            ], $definition),
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

        $definition = $this->definition($repository);

        if ($definition instanceof RedirectResponse) {
            return $definition;
        }

        $validated = $request->validate($this->rules($definition, false), $this->messages($definition));

        return $this->save($request->user(), $repository, $slug, $validated['fields'], (string) ($validated['body'] ?? ''), false);
    }

    /**
     * Copy the repository's agents into sixth-sense again.
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
    private function save(User $user, GithubRepository $repository, string $slug, array $fields, string $body, bool $creating): RedirectResponse
    {
        try {
            $this->documents->write($repository, $slug, $fields, $body, $creating);
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

            return redirect()->route('settings.archive.agents.edit', $slug);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $creating ? 'Agente creado.' : 'Agente actualizado.',
        ]);

        return redirect()->route('settings.archive.agents.edit', $slug);
    }

    /**
     * The type's fields, or a redirect when the type file cannot be used.
     *
     * @return list<array{key: string, label: string}>|RedirectResponse
     */
    private function definition(GithubRepository $repository): array|RedirectResponse
    {
        try {
            $definition = $this->documents->definition($repository);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('settings.archive.agents.index')
                ->withErrors(['agent' => 'No he podido leer types/agent.yml.']);
        }

        if ($definition === []) {
            return $this->missingType();
        }

        return $definition;
    }

    /**
     * @param  list<array{key: string, label: string}>  $definition
     * @return array<string, mixed>
     */
    private function rules(array $definition, bool $creating): array
    {
        $rules = [
            'body' => ['nullable', 'string', 'max:20000'],
            'fields' => ['required', 'array'],
        ];

        foreach ($definition as $field) {
            $rules['fields.'.$field['key']] = ['required', 'string', 'max:2000'];
        }

        if ($creating) {
            $rules['slug'] = ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'];
        }

        return $rules;
    }

    /**
     * @param  list<array{key: string, label: string}>  $definition
     * @return array<string, string>
     */
    private function messages(array $definition): array
    {
        $messages = [
            'slug.required' => 'Escribe la carpeta del agente.',
            'slug.regex' => 'La carpeta solo puede llevar minúsculas, números y guiones.',
            'slug.max' => 'La carpeta es demasiado larga.',
            'body.max' => 'El markdown es demasiado largo.',
        ];

        foreach ($definition as $field) {
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

    private function missingType(): RedirectResponse
    {
        return redirect()
            ->route('settings.archive.agents.index')
            ->withErrors(['agent' => 'El archivo no tiene types/agent.yml con los campos del agente.']);
    }
}
