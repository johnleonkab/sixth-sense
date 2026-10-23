<?php

namespace App\Sense;

use App\Connectors\GitHub\GithubApi;
use App\Connectors\GitHub\GithubContents;
use App\Models\User;

/**
 * Copies agents/{slug}/agent.md from the work repository into sixth-sense.
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

        foreach ($this->api->paths($repository) as $path) {
            if (preg_match('#^agents/([^/]+)/agent\.md$#', $path, $matches) !== 1 || $matches[1] === '_template') {
                continue;
            }

            $file = $this->contents->read($repository, $path);
            $agent = $this->agent($matches[1], $path, $file['content'] ?? '', $file['sha'] ?? null);

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
     * @return array{name: string, purpose: string, cue: string, instructions: string, source_path: string, source_sha: ?string}|null
     */
    private function agent(string $slug, string $path, string $contents, ?string $sha): ?array
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
            'source_path' => $path,
            'source_sha' => $sha,
        ];
    }
}
