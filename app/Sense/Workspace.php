<?php

namespace App\Sense;

use App\Connections\Capability;
use App\Connectors\GitHub\GithubApi;
use App\Connectors\GitHub\GithubContents;
use App\Models\ConnectedAccount;
use App\Models\GithubRepository;
use RuntimeException;

/**
 * Points one repository at the person's work archive: an existing one that
 * already has the scaffold, or a new private one that receives it in full.
 */
class Workspace
{
    public function __construct(
        private GithubApi $api,
        private GithubContents $contents,
        private SyncAgents $agents,
    ) {}

    /**
     * Use this repository as the work archive. It has to be writable and
     * already contain every scaffold file.
     *
     * @throws NotWritable
     * @throws ScaffoldIncomplete
     */
    public function adopt(GithubRepository $repository): GithubRepository
    {
        $this->allowWrites($repository);
        $this->check($repository);
        $this->mark($repository);
        $this->agents->sync($repository->account->user);

        return $repository->refresh();
    }

    /**
     * Add the scaffold files that are missing, then use the repository.
     *
     * @throws NotWritable
     * @throws ScaffoldIncomplete
     */
    public function fill(GithubRepository $repository): GithubRepository
    {
        $this->allowWrites($repository);
        $present = $this->api->paths($repository);

        foreach (Scaffold::files() as $path => $contents) {
            if (in_array($path, $present, true)) {
                continue;
            }

            $this->contents->update($repository, $path, $contents, 'Andamiaje del archivo de trabajo');
        }

        return $this->adopt($repository);
    }

    /**
     * Create a private repository called sense and fill it.
     *
     * @throws NotWritable
     * @throws ScaffoldIncomplete
     */
    public function create(ConnectedAccount $account): GithubRepository
    {
        if (! $account->can(Capability::GithubWrite)) {
            throw new NotWritable('Activa «Actualizar código» en esta cuenta de GitHub antes de crear el archivo.');
        }

        try {
            $created = $this->api->createRepository($account, 'sense', 'Archivo de trabajo de sixth-sense');
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), '422')) {
                throw new NotWritable('Ya hay un repositorio llamado sense en esta cuenta. Puedes usarlo como archivo.');
            }

            throw $exception;
        }

        $owner = is_array($created['owner'] ?? null) ? (string) ($created['owner']['login'] ?? '') : '';

        $repository = $account->githubRepositories()->create([
            'github_id' => (int) $created['id'],
            'owner' => $owner,
            'name' => (string) ($created['name'] ?? 'sense'),
            'full_name' => (string) ($created['full_name'] ?? "{$owner}/sense"),
            'is_private' => true,
            'default_branch' => (string) ($created['default_branch'] ?? 'main'),
            'can_push' => true,
            'included' => true,
            'writes_code' => true,
        ]);

        return $this->fill($repository);
    }

    /**
     * @throws NotWritable
     */
    private function allowWrites(GithubRepository $repository): void
    {
        if (! $repository->can_push || ! $repository->account->can(Capability::GithubWrite)) {
            throw new NotWritable("Esta cuenta no puede escribir en {$repository->full_name}.");
        }

        $repository->update(['included' => true, 'writes_code' => true]);
    }

    /**
     * @throws ScaffoldIncomplete
     */
    private function check(GithubRepository $repository): void
    {
        $present = $this->api->paths($repository);
        $problems = [];

        foreach (array_diff(Scaffold::paths(), $present) as $path) {
            $problems[] = "Falta {$path}.";
        }

        foreach (Scaffold::typePaths() as $path) {
            if (! in_array($path, $present, true)) {
                continue;
            }

            $file = $this->contents->read($repository, $path);
            $problem = DocumentRules::violation($path, $file['content'] ?? '', null);

            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        if ($problems !== []) {
            throw new ScaffoldIncomplete($problems);
        }
    }

    private function mark(GithubRepository $repository): void
    {
        $accountIds = $repository->account->user->connectedAccounts()->select('id');

        GithubRepository::query()
            ->whereIn('connected_account_id', $accountIds)
            ->whereKeyNot($repository->id)
            ->update(['is_work' => false]);

        $repository->update(['is_work' => true]);
    }
}
