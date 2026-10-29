<?php

namespace App\Sense;

use App\Connectors\GitHub\GithubApi;
use App\Connectors\GitHub\GithubContents;
use App\Models\GithubRepository;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Copies the work repository's Claude Code setup into sixth-sense: the
 * agents in .claude/agents/ (subfolders included), the skills in
 * .claude/skills/{name}/SKILL.md, and CLAUDE.md as memory.
 *
 * As in Claude Code, an agent is known by its `name`, not its file, and
 * needs a `description`. A skill's name defaults to its folder.
 */
class SyncAgents
{
    public function __construct(
        private GithubApi $api,
        private GithubContents $contents,
    ) {}

    /**
     * How many agents are stored after the copy. Agents and skills whose
     * file disappeared are removed.
     */
    public function sync(User $user): int
    {
        $repository = $user->workRepository();

        if ($repository === null) {
            $user->senseAgents()->delete();
            $user->senseSkills()->delete();

            return 0;
        }

        $paths = $this->api->paths($repository);
        sort($paths);

        $this->memory($repository, $paths);
        $this->skills($user, $repository, $paths);

        return $this->agents($user, $repository, $paths);
    }

    /**
     * @param  list<string>  $paths
     */
    private function memory(GithubRepository $repository, array $paths): void
    {
        $file = in_array(ClaudeLayout::MEMORY, $paths, true) ? $this->contents->read($repository, ClaudeLayout::MEMORY) : null;
        $memory = $file === null ? null : trim($file['content']);

        $repository->update(['memory' => $memory === '' ? null : $memory]);
    }

    /**
     * @param  list<string>  $paths
     */
    private function agents(User $user, GithubRepository $repository, array $paths): int
    {
        $found = [];

        foreach ($paths as $path) {
            if (! ClaudeLayout::isAgent($path)) {
                continue;
            }

            $file = $this->contents->read($repository, $path);
            $agent = $this->agent($path, $file['content'] ?? '', $file['sha'] ?? null);

            // The first file with a name keeps it, as one location wins in Claude Code.
            if ($agent === null || isset($found[$agent['slug']])) {
                continue;
            }

            $user->senseAgents()->updateOrCreate(['slug' => $agent['slug']], $agent);
            $found[$agent['slug']] = true;
        }

        $this->prune($user->senseAgents(), 'slug', array_keys($found));

        return count($found);
    }

    /**
     * @param  list<string>  $paths
     */
    private function skills(User $user, GithubRepository $repository, array $paths): void
    {
        $found = [];

        foreach ($paths as $path) {
            $folder = ClaudeLayout::skillFolder($path);

            if ($folder === null) {
                continue;
            }

            $file = $this->contents->read($repository, $path);
            $skill = $this->skill($folder, $path, $file['content'] ?? '', $file['sha'] ?? null, $paths);

            if ($skill === null || isset($found[$skill['name']])) {
                continue;
            }

            $user->senseSkills()->updateOrCreate(['name' => $skill['name']], $skill);
            $found[$skill['name']] = true;
        }

        $this->prune($user->senseSkills(), 'name', array_keys($found));
    }

    /**
     * @return array{slug: string, name: string, description: string, instructions: string, skills: list<string>, source_path: string, source_sha: ?string}|null
     */
    private function agent(string $path, string $contents, ?string $sha): ?array
    {
        $document = DocumentRules::document($contents);

        if ($document === null || isset($document['_invalid'])) {
            return null;
        }

        $name = is_string($document['name'] ?? null) ? trim($document['name']) : '';
        $description = is_string($document['description'] ?? null) ? trim($document['description']) : '';

        if ($name === '' || $description === '' || str_contains($name, ':') || mb_strlen($name) > 255) {
            return null;
        }

        return [
            'slug' => $name,
            'name' => $name,
            'description' => $description,
            'instructions' => trim((string) ($document['_body'] ?? '')),
            'skills' => ClaudeLayout::names($document['skills'] ?? null),
            'source_path' => $path,
            'source_sha' => $sha,
        ];
    }

    /**
     * @param  list<string>  $paths
     * @return array{name: string, description: string, instructions: string, files: list<string>, model_invocable: bool, source_path: string, source_sha: ?string}|null
     */
    private function skill(string $folder, string $path, string $contents, ?string $sha, array $paths): ?array
    {
        $document = DocumentRules::document($contents);

        if (isset($document['_invalid'])) {
            return null;
        }

        $front = $document ?? [];
        $body = trim((string) ($document['_body'] ?? $contents));
        $name = is_string($front['name'] ?? null) && trim($front['name']) !== '' ? trim($front['name']) : $folder;
        $prefix = ClaudeLayout::SKILLS."{$folder}/";

        return [
            'name' => mb_substr($name, 0, 255),
            'description' => ClaudeLayout::skillDescription($front, $body),
            'instructions' => $body,
            'files' => array_values(array_filter(
                $paths,
                fn (string $candidate): bool => str_starts_with($candidate, $prefix) && $candidate !== $path,
            )),
            'model_invocable' => ($front['disable-model-invocation'] ?? false) !== true,
            'source_path' => $path,
            'source_sha' => $sha,
        ];
    }

    /**
     * @param  HasMany<*, *>  $relation
     * @param  list<string>  $kept
     */
    private function prune(HasMany $relation, string $column, array $kept): void
    {
        if ($kept === []) {
            $relation->delete();

            return;
        }

        $relation->whereNotIn($column, $kept)->delete();
    }
}
