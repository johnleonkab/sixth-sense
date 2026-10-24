<?php

namespace App\Sense;

use App\Connectors\GitHub\GithubApi;
use App\Connectors\GitHub\GithubContents;
use App\Models\GithubRepository;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Markdown documents on the archive's main branch. Saving commits there.
 * It does not open a pull request or any other branch.
 */
class ArchiveFiles
{
    public const BRANCH = 'main';

    public function __construct(
        private GithubApi $api,
        private GithubContents $contents,
        private FileRules $rules,
        private SyncAgents $agents,
    ) {}

    /**
     * Markdown paths on main, in path order.
     *
     * @return list<string>
     */
    public function list(GithubRepository $repository): array
    {
        $paths = [];

        foreach ($this->api->paths($repository, self::BRANCH) as $path) {
            if ($this->documentPath($path) !== null) {
                $paths[] = $path;
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * @return array{content: string, sha: string}|null
     */
    public function read(GithubRepository $repository, string $path): ?array
    {
        $path = $this->documentPath($path);

        if ($path === null) {
            return null;
        }

        return $this->contents->read($repository, $path, self::BRANCH);
    }

    /**
     * Commit this markdown onto main. A missing file is not created.
     * Returns false when the text is already what's on main.
     *
     * @throws ValidationException
     */
    public function save(User $user, GithubRepository $repository, string $path, string $contents): bool
    {
        $path = $this->documentPath($path);

        if ($path === null) {
            throw ValidationException::withMessages([
                'contents' => 'Ese documento no se puede guardar.',
            ]);
        }

        if (! $repository->mayUpdateCode()) {
            throw ValidationException::withMessages([
                'contents' => 'Este archivo es de solo lectura. Permite actualizar su código para guardar en main.',
            ]);
        }

        $current = $this->contents->read($repository, $path, self::BRANCH);

        if ($current === null) {
            throw ValidationException::withMessages([
                'contents' => 'Ese documento no está en main.',
            ]);
        }

        if ($current['content'] === $contents) {
            return false;
        }

        $problem = $this->rules->violation($repository, $path, $contents, self::BRANCH);

        if ($problem !== null) {
            throw ValidationException::withMessages(['contents' => $problem]);
        }

        $this->contents->update($repository, $path, $contents, "Actualiza {$path}", self::BRANCH);

        if (preg_match('#^agents/(?!_template(?:/|$))[^/]+/agent\.md$#', $path) === 1) {
            try {
                $this->agents->sync($user);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return true;
    }

    /**
     * A repository-relative markdown path, or null when it is not one.
     */
    public function documentPath(string $path): ?string
    {
        $path = DocumentRules::path(rawurldecode($path));

        if ($path === null || ! str_ends_with($path, '.md')) {
            return null;
        }

        return $path;
    }
}
