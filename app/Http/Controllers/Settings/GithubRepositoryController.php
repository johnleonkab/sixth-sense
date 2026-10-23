<?php

namespace App\Http\Controllers\Settings;

use App\Connections\Capability;
use App\Connections\Provider;
use App\Connectors\GitHub\GithubRepositories;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\GithubRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class GithubRepositoryController extends Controller
{
    /**
     * Fetch the account's repositories again, keeping the choices already made.
     */
    public function refresh(Request $request, GithubRepositories $repositories, int $account): RedirectResponse
    {
        $account = $this->account($request, $account);

        try {
            $repositories->sync($account);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'repositories' => 'No he podido leer los repositorios de GitHub. Inténtalo de nuevo.',
            ]);
        }

        return back();
    }

    /**
     * Choose whether sixth-sense uses a repository, and whether it may update its code.
     */
    public function update(Request $request, int $account, int $repository): RedirectResponse
    {
        $account = $this->account($request, $account);
        $repository = $account->githubRepositories()->findOrFail($repository);
        $choice = $request->validate([
            'included' => ['required', 'boolean'],
            'writes_code' => ['required', 'boolean'],
        ]);

        $included = (bool) $choice['included'];
        $writes = (bool) $choice['writes_code'];

        if ($included && $writes) {
            $this->ensureWritable($account, $repository);
        }

        $repository->update([
            'included' => $included,
            'writes_code' => $included && $writes,
        ]);

        return back();
    }

    private function account(Request $request, int $account): ConnectedAccount
    {
        return $request->user()->connectedAccounts()
            ->where('provider', Provider::GitHub)
            ->findOrFail($account);
    }

    private function ensureWritable(ConnectedAccount $account, GithubRepository $repository): void
    {
        if (! $account->can(Capability::GithubWrite)) {
            throw ValidationException::withMessages([
                'writes_code' => 'Activa «Actualizar código» en la cuenta antes de permitirlo en un repositorio.',
            ]);
        }

        if (! $repository->can_push) {
            throw ValidationException::withMessages([
                'writes_code' => "Esta cuenta no puede escribir en {$repository->full_name}.",
            ]);
        }
    }
}
