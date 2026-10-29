<?php

namespace App\Connectors\GitHub;

use App\Models\GithubRepository;
use RuntimeException;

/**
 * Reads and updates a file in a repository the person connected. Updating
 * code is refused unless they included that repository and allowed it.
 */
class GithubContents
{
    public function __construct(private GithubApi $api) {}

    /**
     * Replace or create a file and return the new commit.
     *
     * @return array{path: string, sha: string, commit: string, url: string}
     *
     * @throws GithubWriteNotAllowed
     */
    public function update(GithubRepository $repository, string $path, string $contents, string $message, ?string $branch = null): array
    {
        if (! $repository->mayUpdateCode()) {
            throw new GithubWriteNotAllowed($repository);
        }

        $branch ??= $repository->default_branch;
        $current = $this->api->file($repository, $path, $branch);
        $body = [
            'message' => $message,
            'content' => base64_encode($contents),
            'branch' => $branch,
        ];

        if (is_array($current) && filled($current['sha'] ?? null)) {
            $body['sha'] = $current['sha'];
        }

        $updated = $this->api->putContents($repository, $path, $body);

        return [
            'path' => (string) ($updated['content']['path'] ?? ltrim($path, '/')),
            'sha' => (string) ($updated['content']['sha'] ?? ''),
            'commit' => (string) ($updated['commit']['sha'] ?? ''),
            'url' => (string) ($updated['commit']['html_url'] ?? ''),
        ];
    }

    /**
     * Remove a file. It has to exist; GitHub deletes by the blob's sha.
     *
     * @return array{path: string, sha: string, commit: string, url: string}
     *
     * @throws GithubWriteNotAllowed
     */
    public function delete(GithubRepository $repository, string $path, string $message, ?string $branch = null): array
    {
        if (! $repository->mayUpdateCode()) {
            throw new GithubWriteNotAllowed($repository);
        }

        $branch ??= $repository->default_branch;
        $current = $this->read($repository, $path, $branch);

        if ($current === null) {
            throw new RuntimeException("No está el archivo {$path}.");
        }

        $deleted = $this->api->deleteContents($repository, $path, [
            'message' => $message,
            'sha' => $current['sha'],
            'branch' => $branch,
        ]);

        return [
            'path' => ltrim($path, '/'),
            'sha' => (string) ($deleted['commit']['sha'] ?? ''),
            'commit' => (string) ($deleted['commit']['sha'] ?? ''),
            'url' => (string) ($deleted['commit']['html_url'] ?? ''),
        ];
    }

    /**
     * The text of a file, or null when the repository does not have it.
     *
     * @return array{content: string, sha: string}|null
     */
    public function read(GithubRepository $repository, string $path, ?string $branch = null): ?array
    {
        $file = $this->api->file($repository, $path, $branch ?? $repository->default_branch);

        if (! is_array($file) || ! is_string($file['content'] ?? null)) {
            return null;
        }

        $content = base64_decode(str_replace("\n", '', $file['content']), true);

        if ($content === false) {
            return null;
        }

        return ['content' => $content, 'sha' => (string) ($file['sha'] ?? '')];
    }
}
