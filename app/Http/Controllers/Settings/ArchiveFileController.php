<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\GithubRepository;
use App\Models\User;
use App\Sense\ArchiveFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Reads, creates and commits the archive's markdown on main. A new document
 * follows types/{type}.yml. A new folder copies the project template or starts
 * an agent in .claude/agents/; a skill file goes in .claude/skills/{skill}/.
 * There is no other branch.
 */
class ArchiveFileController extends Controller
{
    public function __construct(private ArchiveFiles $files) {}

    public function index(Request $request): Response
    {
        $repository = $request->user()->workRepository();

        if ($repository === null) {
            return Inertia::render('settings/archive', [
                'repository' => null,
                'agents' => [],
            ]);
        }

        try {
            $files = $this->files->list($repository);
        } catch (Throwable $exception) {
            report($exception);

            return $this->page($repository, [], 'No he podido leer los documentos de main.');
        }

        return $this->page($repository, $files);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $repository = $this->archive($request->user());

        if ($repository instanceof RedirectResponse) {
            return $repository;
        }

        try {
            $files = $this->files->list($repository);
            $types = $this->files->types($repository);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('settings.archive.files.index')
                ->withErrors(['contents' => 'No he podido leer las reglas del archivo.']);
        }

        return $this->page($repository, $files, null, null, true, $types);
    }

    public function store(Request $request): RedirectResponse
    {
        $repository = $this->archive($request->user());

        if ($repository instanceof RedirectResponse) {
            return $repository;
        }

        if ($request->string('kind')->toString() === 'folder') {
            return $this->storeFolder($request, $repository);
        }

        if ($request->string('kind')->toString() === 'skill') {
            return $this->storeSkillFile($request, $repository);
        }

        $validated = $request->validate([
            'kind' => ['required', 'in:file'],
            'type' => ['required', 'string', 'max:40'],
            'project' => ['nullable', 'string', 'max:80'],
            'slug' => ['nullable', 'string', 'max:80'],
            'date' => ['nullable', 'string', 'max:10'],
            'fields' => ['present', 'array'],
            'fields.*' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:100000'],
        ], [
            'kind.in' => 'Elige un documento o una carpeta.',
            'type.required' => 'Elige un tipo.',
            'fields.present' => 'Faltan los campos del tipo.',
            'body.max' => 'El documento es demasiado largo.',
        ]);

        /** @var array<string, string> $fields */
        $fields = [];

        foreach ($validated['fields'] as $key => $value) {
            if (is_string($key)) {
                $fields[$key] = is_string($value) ? $value : '';
            }
        }

        try {
            $path = $this->files->createFile(
                $request->user(),
                $repository,
                $validated['type'],
                [
                    'project' => (string) ($validated['project'] ?? ''),
                    'slug' => (string) ($validated['slug'] ?? ''),
                    'date' => (string) ($validated['date'] ?? ''),
                ],
                $fields,
                (string) ($validated['body'] ?? ''),
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['contents' => 'No he podido crear el documento en main.'])->withInput();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Creado en main.']);

        return redirect('/settings/archive/files/'.$path);
    }

    public function show(Request $request, string $path): Response|RedirectResponse
    {
        $path = $this->files->documentPath($path);

        if ($path === null) {
            abort(404);
        }

        $repository = $this->archive($request->user());

        if ($repository instanceof RedirectResponse) {
            return $repository;
        }

        try {
            $file = $this->files->read($repository, $path);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('settings.archive.files.index')
                ->withErrors(['contents' => 'No he podido leer ese documento.']);
        }

        if ($file === null) {
            abort(404);
        }

        try {
            $files = $this->files->list($repository);
        } catch (Throwable $exception) {
            report($exception);
            $files = [];
        }

        return $this->page($repository, $files, null, [
            'path' => $path,
            'contents' => $file['content'],
            'branch' => ArchiveFiles::BRANCH,
        ]);
    }

    public function update(Request $request, string $path): RedirectResponse
    {
        $path = $this->files->documentPath($path);

        if ($path === null) {
            abort(404);
        }

        $repository = $this->archive($request->user());

        if ($repository instanceof RedirectResponse) {
            return $repository;
        }

        $validated = $request->validate([
            'contents' => ['present', 'string', 'max:100000'],
        ], [
            'contents.present' => 'Falta el documento.',
            'contents.max' => 'El documento es demasiado largo.',
        ]);

        try {
            $changed = $this->files->save($request->user(), $repository, $path, $validated['contents']);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['contents' => 'No he podido guardar el documento en main.'])->withInput();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $changed ? 'Guardado en main.' : 'Sin cambios.',
        ]);

        return back();
    }

    private function storeSkillFile(Request $request, GithubRepository $repository): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'in:skill'],
            'skill' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:100000'],
        ], [
            'skill.required' => 'Escribe la skill.',
            'name.required' => 'Falta el nombre.',
            'body.required' => 'Falta el documento.',
            'body.max' => 'El documento es demasiado largo.',
        ]);

        try {
            $path = $this->files->createSkillFile($request->user(), $repository, $validated['skill'], $validated['name'], $validated['body']);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['body' => 'No he podido crear el archivo de la skill en main.'])->withInput();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Creado en main.']);

        return redirect('/settings/archive/files/'.$path);
    }

    private function storeFolder(Request $request, GithubRepository $repository): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'in:folder'],
            'folder' => ['required', 'in:project,agent'],
            'name' => ['required', 'string', 'max:80', 'not_in:_template'],
        ], [
            'folder.required' => 'Elige qué carpeta crear.',
            'folder.in' => 'Esa carpeta no forma parte del archivo.',
            'name.required' => 'Falta el nombre.',
            'name.not_in' => 'Ese nombre está reservado para la plantilla.',
        ]);

        try {
            $path = $this->files->createFolder($request->user(), $repository, $validated['folder'], $validated['name']);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['name' => 'No he podido crear la carpeta en main.'])->withInput();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Creado en main.']);

        return redirect('/settings/archive/files/'.$path);
    }

    /**
     * @param  list<string>  $files
     * @param  array{path: string, contents: string, branch: string}|null  $document
     * @param  list<array<string, mixed>>  $types
     */
    private function page(GithubRepository $repository, array $files, ?string $error = null, ?array $document = null, bool $creating = false, array $types = []): Response
    {
        return Inertia::render('settings/archive-files', [
            'repository' => $this->repository($repository),
            'files' => $files,
            'error' => $error,
            'document' => $document,
            'creating' => $creating,
            'types' => $types,
        ]);
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
        return $user->workRepository() ?? redirect()->route('settings.archive.files.index');
    }
}
