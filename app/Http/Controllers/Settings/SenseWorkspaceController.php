<?php

namespace App\Http\Controllers\Settings;

use App\Connections\Provider;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\GithubRepository;
use App\Sense\NotWritable;
use App\Sense\ScaffoldIncomplete;
use App\Sense\SyncAgents;
use App\Sense\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Throwable;

/**
 * Chooses the one GitHub repository that holds the person's work archive,
 * creates it when they don't have one, and copies its agents into sixth-sense.
 */
class SenseWorkspaceController extends Controller
{
    public function __construct(
        private Workspace $workspace,
        private SyncAgents $agents,
    ) {}

    /**
     * Create a private repository called sense and make it the work archive.
     */
    public function store(Request $request, int $account): RedirectResponse
    {
        $account = $this->account($request, $account);

        try {
            $repository = $this->workspace->create($account);
        } catch (NotWritable|ScaffoldIncomplete $exception) {
            return back()->withErrors(['workspace' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['workspace' => 'No he podido crear el archivo en GitHub.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Archivo creado en {$repository->full_name}."]);

        return back();
    }

    /**
     * Use an existing repository, when it already has the scaffold.
     */
    public function adopt(Request $request, int $account, int $repository): RedirectResponse
    {
        return $this->attempt($request, $account, $repository, fn (GithubRepository $repository) => $this->workspace->adopt($repository), 'Archivo de trabajo actualizado.');
    }

    /**
     * Add the missing scaffold files, then use the repository.
     */
    public function fill(Request $request, int $account, int $repository): RedirectResponse
    {
        return $this->attempt($request, $account, $repository, fn (GithubRepository $repository) => $this->workspace->fill($repository), 'Andamiaje completado. Este es el archivo de trabajo.');
    }

    /**
     * Copy agents from the work archive into sixth-sense.
     */
    public function sync(Request $request, int $account, int $repository): RedirectResponse
    {
        $repository = $this->repository($request, $account, $repository);
        abort_unless($repository->is_work, 404);

        try {
            $count = $this->agents->sync($request->user());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['workspace' => 'No he podido leer los agentes en GitHub.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Agentes sincronizados: {$count}."]);

        return back();
    }

    private function attempt(Request $request, int $account, int $repository, callable $run, string $message): RedirectResponse
    {
        $repository = $this->repository($request, $account, $repository);

        try {
            $run($repository);
        } catch (NotWritable|ScaffoldIncomplete $exception) {
            return back()->withErrors(['workspace' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['workspace' => 'No he podido preparar el archivo en GitHub.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    private function account(Request $request, int $account): ConnectedAccount
    {
        $account = $request->user()->connectedAccounts()->findOrFail($account);
        abort_unless($account->provider === Provider::GitHub, 404);

        return $account;
    }

    private function repository(Request $request, int $account, int $repository): GithubRepository
    {
        return $this->account($request, $account)->githubRepositories()->findOrFail($repository);
    }
}
