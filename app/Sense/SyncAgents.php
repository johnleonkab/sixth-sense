<?php

namespace App\Sense;

use App\Connectors\GitHub\GithubApi;
use App\Connectors\GitHub\GithubContents;
use App\Models\GithubRepository;
use App\Models\User;

/**
 * Copies agents/{slug}/agent.md from the work repository into sixth-sense,
 * and the markdown in agents/{slug}/references/ with it.
 * The template is an example, not an agent the voice can use.
 */
class SyncAgents
{
    public function __construct(
        private GithubApi $api,
        private GithubContents $contents,
    ) {}

    /**
     * How many agents are stored after the copy. Agents whose file disappeared
     * are removed.
     */
    public function sync(User $user): int
    {
        $repository = $user->workRepository();

        if ($repository === null) {
            $user->senseAgents()->delete();

            return 0;
        }

        $found = [];
        $paths = $this->api->paths($repository);
        $references = [];

        foreach ($paths as $path) {
            if (preg_match('#^agents/([^/]+)/references/(.+\.md)$#', $path, $matches) !== 1 || $matches[1] === '_template') {
                continue;
            }

            $references[$matches[1]][] = $path;
        }

        foreach ($paths as $path) {
            if (preg_match('#^agents/([^/]+)/agent\.md$#', $path, $matches) !== 1 || $matches[1] === '_template') {
                continue;
            }

            $file = $this->contents->read($repository, $path);
            $agent = $this->agent(
                $matches[1],
                $path,
                $file['content'] ?? '',
                $file['sha'] ?? null,
                $this->references($repository, $matches[1], $references[$matches[1]] ?? []),
            );

            if ($agent === null) {
                continue;
            }

            $user->senseAgents()->updateOrCreate(['slug' => $matches[1]], $agent);
            $found[] = $matches[1];
        }

        $gone = $user->senseAgents();

        if ($found === []) {
            $gone->delete();
        } else {
            $gone->whereNotIn('slug', $found)->delete();
        }

        return count($found);
    }

    /**
     * @return array{name: string, purpose: string, cue: string, instructions: string, references: string, source_path: string, source_sha: ?string}|null
     */
    private function agent(string $slug, string $path, string $contents, ?string $sha, string $references): ?array
    {
        $document = DocumentRules::document($contents);

        if ($document === null || isset($document['_invalid'])) {
            return null;
        }

        $name = trim((string) ($document['name'] ?? ''));
        $purpose = trim((string) ($document['purpose'] ?? ''));
        $cue = trim((string) ($document['when'] ?? ''));

        if ($name === '' || $purpose === '' || $cue === '') {
            return null;
        }

        return [
            'name' => $name,
            'purpose' => $purpose,
            'cue' => $cue,
            'instructions' => trim((string) ($document['_body'] ?? '')),
            'references' => $references,
            'source_path' => $path,
            'source_sha' => $sha,
        ];
    }

    /**
     * @param  list<string>  $paths
     */
    private function references(GithubRepository $repository, string $slug, array $paths): string
    {
        sort($paths);
        $blocks = [];

        foreach ($paths as $path) {
            $file = $this->contents->read($repository, $path);

            if ($file === null) {
                continue;
            }

            $relative = substr($path, strlen("agents/{$slug}/"));
            $blocks[] = "# {$relative}\n\n".trim($file['content']);
        }

        return implode("\n\n", $blocks);
    }
}
