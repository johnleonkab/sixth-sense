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
     * The types a new document can follow, read from types/*.yml on main.
     *
     * @return list<array{type: string, where: string, name: string, required: list<array{key: string, label: string}>, optional: list<array{key: string, label: string}>, slots: list<string>}>
     */
    public function types(GithubRepository $repository): array
    {
        $types = [];

        foreach ($this->api->paths($repository, self::BRANCH) as $path) {
            if (preg_match('#^types/([a-z0-9-]+)\.yml$#', $path, $matches) !== 1) {
                continue;
            }

            $file = $this->contents->read($repository, $path, self::BRANCH);
            $spec = is_array($file) ? DocumentRules::parse($file['content']) : null;

            if (! is_array($spec) || ($spec['type'] ?? null) !== $matches[1]) {
                continue;
            }

            $where = $spec['where'] ?? null;
            $name = $spec['name'] ?? null;

            if (! is_string($where) || ! is_string($name) || $where === '' || $name === '') {
                continue;
            }

            $types[] = [
                'type' => $matches[1],
                'where' => $where,
                'name' => $name,
                'required' => $this->pairs($spec['required'] ?? null),
                'optional' => $this->pairs($spec['optional'] ?? null),
                'slots' => DocumentRules::slots($where, $name),
            ];
        }

        usort($types, fn (array $left, array $right): int => $left['type'] <=> $right['type']);

        return $types;
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

        $this->writable($repository);

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
        $this->syncAgent($user, $path);

        return true;
    }

    /**
     * Commit a new markdown document on main. The path comes from the type's
     * where and name, not from the request. Returns that path.
     *
     * @param  array<string, string>  $slots
     * @param  array<string, string>  $fields
     *
     * @throws ValidationException
     */
    public function createFile(User $user, GithubRepository $repository, string $type, array $slots, array $fields, string $body): string
    {
        $this->writable($repository);

        if (preg_match('/^[a-z0-9-]+$/', $type) !== 1) {
            throw ValidationException::withMessages(['type' => 'Ese tipo no existe en types/.']);
        }

        $file = $this->contents->read($repository, "types/{$type}.yml", self::BRANCH);
        $spec = is_array($file) ? DocumentRules::parse($file['content']) : null;

        if (! is_array($spec) || ($spec['type'] ?? null) !== $type) {
            throw ValidationException::withMessages([
                'type' => "No hay reglas para el tipo «{$type}». Crea types/{$type}.yml antes.",
            ]);
        }

        $problem = DocumentRules::slotProblem($spec, $slots);

        if ($problem !== null) {
            throw ValidationException::withMessages(['contents' => $problem]);
        }

        $path = DocumentRules::locate($spec, $slots);

        if ($path === null || ! str_ends_with($path, '.md')) {
            throw ValidationException::withMessages(['contents' => 'La ruta no cumple where y name de ese tipo.']);
        }

        if ($this->contents->read($repository, $path, self::BRANCH) !== null) {
            throw ValidationException::withMessages(['contents' => 'Ese documento ya está en main.']);
        }

        $contents = DocumentRules::compose($type, $spec, $fields, $body);
        $problem = $this->rules->violation($repository, $path, $contents, self::BRANCH);

        if ($problem !== null) {
            throw ValidationException::withMessages(['contents' => $problem]);
        }

        $this->contents->update($repository, $path, $contents, "Crea {$path}", self::BRANCH);
        $this->syncAgent($user, $path);

        return $path;
    }

    /**
     * Commit a folder the archive knows how to start: a project copied from
     * projects/_template, or an agent copied from agents/_template.
     * Returns the document to open afterwards.
     *
     * @throws ValidationException
     */
    public function createFolder(User $user, GithubRepository $repository, string $kind, string $name): string
    {
        $this->writable($repository);

        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) !== 1 || $name === '_template') {
            throw ValidationException::withMessages(['name' => 'El nombre va en minúsculas, números y guiones.']);
        }

        return match ($kind) {
            'project' => $this->projectFolder($repository, $name),
            'agent' => $this->agentFolder($user, $repository, $name),
            default => throw ValidationException::withMessages(['folder' => 'Esa carpeta no forma parte del archivo.']),
        };
    }

    /**
     * Commit one markdown file in agents/{agent}/references/. The folder
     * appears with the first file. Returns the path.
     *
     * @throws ValidationException
     */
    public function createReference(User $user, GithubRepository $repository, string $agent, string $name, string $contents): string
    {
        $this->writable($repository);

        if (! $this->slug($agent) || $agent === '_template') {
            throw ValidationException::withMessages(['agent' => 'El agente va en minúsculas, números y guiones.']);
        }

        if (! $this->slug($name)) {
            throw ValidationException::withMessages(['name' => 'El nombre va en minúsculas, números y guiones.']);
        }

        if (trim($contents) === '') {
            throw ValidationException::withMessages(['body' => 'Falta el documento.']);
        }

        if ($this->contents->read($repository, "agents/{$agent}/agent.md", self::BRANCH) === null) {
            throw ValidationException::withMessages(['agent' => 'No hay un agente con esa carpeta.']);
        }

        $path = "agents/{$agent}/references/{$name}.md";

        if ($this->contents->read($repository, $path, self::BRANCH) !== null) {
            throw ValidationException::withMessages(['name' => 'Esa referencia ya está en main.']);
        }

        $problem = $this->rules->violation($repository, $path, $contents, self::BRANCH);

        if ($problem !== null) {
            throw ValidationException::withMessages(['body' => $problem]);
        }

        $this->contents->update($repository, $path, $contents, "Crea {$path}", self::BRANCH);
        $this->syncAgent($user, $path);

        return $path;
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

    private function slug(string $name): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) === 1;
    }

    /**
     * @throws ValidationException
     */
    private function writable(GithubRepository $repository): void
    {
        if (! $repository->mayUpdateCode()) {
            throw ValidationException::withMessages([
                'contents' => 'Este archivo es de solo lectura. Permite actualizar su código para guardar en main.',
            ]);
        }
    }

    private function syncAgent(User $user, string $path): void
    {
        if (preg_match('#^agents/(?!_template(?:/|$))[^/]+/#', $path) !== 1) {
            return;
        }

        try {
            $this->agents->sync($user);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function pairs(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        $pairs = [];

        foreach ($fields as $key => $label) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $pairs[] = [
                'key' => $key,
                'label' => is_string($label) && $label !== '' ? $label : $key,
            ];
        }

        return $pairs;
    }

    /**
     * @throws ValidationException
     */
    private function projectFolder(GithubRepository $repository, string $name): string
    {
        $paths = $this->api->paths($repository, self::BRANCH);
        $prefix = "projects/{$name}/";

        foreach ($paths as $path) {
            if (str_starts_with($path, $prefix)) {
                throw ValidationException::withMessages(['name' => 'Ese proyecto ya está en main.']);
            }
        }

        $template = array_values(array_filter(
            $paths,
            fn (string $path): bool => str_starts_with($path, 'projects/_template/') && str_ends_with($path, '.md'),
        ));

        if ($template === []) {
            throw ValidationException::withMessages(['name' => 'No está la plantilla projects/_template.']);
        }

        sort($template);
        $writes = [];

        foreach ($template as $path) {
            $file = $this->contents->read($repository, $path, self::BRANCH);

            if ($file === null) {
                continue;
            }

            $target = $prefix.substr($path, strlen('projects/_template/'));
            $problem = $this->rules->violation($repository, $target, $file['content'], self::BRANCH);

            if ($problem !== null) {
                throw ValidationException::withMessages(['name' => $problem]);
            }

            $writes[$target] = $file['content'];
        }

        if ($writes === []) {
            throw ValidationException::withMessages(['name' => 'No está la plantilla projects/_template.']);
        }

        foreach ($writes as $target => $content) {
            $this->contents->update($repository, $target, $content, "Crea {$target}", self::BRANCH);
        }

        return isset($writes[$prefix.'project.md']) ? $prefix.'project.md' : (string) array_key_first($writes);
    }

    /**
     * @throws ValidationException
     */
    private function agentFolder(User $user, GithubRepository $repository, string $name): string
    {
        $target = "agents/{$name}/agent.md";
        $template = $this->contents->read($repository, 'agents/_template/agent.md', self::BRANCH);

        if ($template === null) {
            throw ValidationException::withMessages(['name' => 'No está la plantilla agents/_template/agent.md.']);
        }

        if ($this->contents->read($repository, $target, self::BRANCH) !== null) {
            throw ValidationException::withMessages(['name' => 'Ese agente ya está en main.']);
        }

        $problem = $this->rules->violation($repository, $target, $template['content'], self::BRANCH);

        if ($problem !== null) {
            throw ValidationException::withMessages(['name' => $problem]);
        }

        $this->contents->update($repository, $target, $template['content'], "Crea {$target}", self::BRANCH);
        $this->syncAgent($user, $target);

        return $target;
    }
}
