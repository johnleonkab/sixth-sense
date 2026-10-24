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
 * Reads and commits the archive's markdown on main. There is no other branch.
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

            return Inertia::render('settings/archive-files', [
                'repository' => $this->repository($repository),
                'files' => [],
                'error' => 'No he podido leer los documentos de main.',
                'document' => null,
            ]);
        }

        return Inertia::render('settings/archive-files', [
            'repository' => $this->repository($repository),
            'files' => $files,
            'error' => null,
            'document' => null,
        ]);
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

        return Inertia::render('settings/archive-files', [
            'repository' => $this->repository($repository),
            'files' => $files,
            'error' => null,
            'document' => [
                'path' => $path,
                'contents' => $file['content'],
                'branch' => ArchiveFiles::BRANCH,
            ],
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
