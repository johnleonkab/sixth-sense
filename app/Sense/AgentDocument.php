<?php

namespace App\Sense;

use App\Connectors\GitHub\GithubContents;
use App\Models\GithubRepository;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads and writes one Claude Code subagent in .claude/agents/: the name,
 * the description, the skills it preloads, and the system prompt below.
 * Any other front matter key (tools, model, color…) is kept as it was.
 */
class AgentDocument
{
    public function __construct(
        private GithubContents $contents,
        private FileRules $rules,
    ) {}

    /**
     * The fields the form edits, in the order Claude Code documents them.
     *
     * @return list<array{key: string, label: string, required: bool}>
     */
    public function definition(): array
    {
        return [
            ['key' => 'description', 'label' => 'Cuándo usarlo', 'required' => true],
            ['key' => 'skills', 'label' => 'Skills que precarga, separadas por comas', 'required' => false],
        ];
    }

    /**
     * The agent file as the form shows it, or null when the path has none.
     *
     * @return array{fields: array<string, string>, body: string, readable: bool}|null
     */
    public function open(GithubRepository $repository, string $path): ?array
    {
        $file = $this->contents->read($repository, $path);

        if ($file === null) {
            return null;
        }

        $document = DocumentRules::document($file['content']);
        $fields = array_fill_keys(array_column($this->definition(), 'key'), '');

        if ($document === null || isset($document['_invalid'])) {
            return ['fields' => $fields, 'body' => $file['content'], 'readable' => false];
        }

        $fields['description'] = is_string($document['description'] ?? null) ? trim($document['description']) : '';
        $fields['skills'] = implode(', ', ClaudeLayout::names($document['skills'] ?? null));

        return [
            'fields' => $fields,
            'body' => trim((string) ($document['_body'] ?? '')),
            'readable' => true,
        ];
    }

    /**
     * Commit the agent and return the markdown that was written. A new agent
     * goes to .claude/agents/{name}.md; an existing one stays where it is.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    public function write(GithubRepository $repository, string $name, ?string $path, array $values, string $body): string
    {
        if (! $repository->mayUpdateCode()) {
            throw ValidationException::withMessages([
                'agent' => 'Este archivo es de solo lectura. Permite actualizar su código para guardar agentes.',
            ]);
        }

        $creating = $path === null;
        $path ??= ClaudeLayout::agentPath($name);
        $current = $this->contents->read($repository, $path);

        if ($creating && $current !== null) {
            throw ValidationException::withMessages(['slug' => 'Ya hay un agente con ese nombre.']);
        }

        if (! $creating && $current === null) {
            throw ValidationException::withMessages(['agent' => 'Ese agente ya no está en el archivo.']);
        }

        $document = $current === null ? null : DocumentRules::document($current['content']);
        $front = is_array($document) && ! isset($document['_invalid']) ? $document : [];
        unset($front['_body']);

        $markdown = $this->markdown($front, $name, $values, $body);
        $problem = $this->rules->violation($repository, $path, $markdown);

        if ($problem !== null) {
            throw ValidationException::withMessages(['agent' => $problem]);
        }

        $this->contents->update($repository, $path, $markdown, $creating ? "Crea el agente {$name}" : "Actualiza el agente {$name}");

        return $markdown;
    }

    /**
     * @param  array<string, mixed>  $front
     * @param  array<string, mixed>  $values
     */
    private function markdown(array $front, string $name, array $values, string $body): string
    {
        $skills = ClaudeLayout::names((string) ($values['skills'] ?? ''));
        $edited = [
            'name' => $name,
            'description' => trim((string) ($values['description'] ?? '')),
        ];

        if ($skills !== []) {
            $edited['skills'] = $skills;
        }

        unset($front['name'], $front['description'], $front['skills']);

        $yaml = rtrim(Yaml::dump([...$edited, ...$front], 2, 2));
        $markdown = rtrim($body);

        return "---\n{$yaml}\n---\n".($markdown === '' ? "\n" : "\n{$markdown}\n");
    }
}
