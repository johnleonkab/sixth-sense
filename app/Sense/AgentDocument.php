<?php

namespace App\Sense;

use App\Connectors\GitHub\GithubContents;
use App\Models\GithubRepository;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads and writes one agents/{slug}/agent.md using the fields declared
 * in the repository's types/agent.yml, plus the markdown that follows.
 */
class AgentDocument
{
    /** @var array<int, list<array{key: string, label: string}>> */
    private array $definitions = [];

    public function __construct(
        private GithubContents $contents,
        private FileRules $rules,
    ) {}

    /**
     * The fields an agent must have, in the order the type file lists them.
     *
     * @return list<array{key: string, label: string}>
     */
    public function definition(GithubRepository $repository): array
    {
        return $this->definitions[$repository->id] ??= $this->load($repository);
    }

    /**
     * The agent file as the form shows it, or null when the folder has none.
     *
     * @return array{fields: array<string, string>, body: string, extra: array<string, string>, readable: bool}|null
     */
    public function open(GithubRepository $repository, string $slug): ?array
    {
        $file = $this->contents->read($repository, $this->path($slug));

        if ($file === null) {
            return null;
        }

        $definition = $this->definition($repository);
        $document = DocumentRules::document($file['content']);
        $fields = [];

        foreach ($definition as $field) {
            $fields[$field['key']] = '';
        }

        if ($document === null || isset($document['_invalid'])) {
            return [
                'fields' => $fields,
                'body' => $file['content'],
                'extra' => [],
                'readable' => false,
            ];
        }

        $extra = [];

        foreach ($document as $key => $value) {
            if (! is_string($key) || $key === 'type' || str_starts_with($key, '_')) {
                continue;
            }

            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $text = trim((string) $value);

            if (array_key_exists($key, $fields)) {
                $fields[$key] = $text;
            } elseif (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1) {
                $extra[$key] = $text;
            }
        }

        return [
            'fields' => $fields,
            'body' => trim((string) ($document['_body'] ?? '')),
            'extra' => $extra,
            'readable' => true,
        ];
    }

    /**
     * Commit the agent and return the markdown that was written.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    public function write(GithubRepository $repository, string $slug, array $values, string $body, bool $creating): string
    {
        if (! $repository->mayUpdateCode()) {
            throw ValidationException::withMessages([
                'agent' => 'Este archivo es de solo lectura. Permite actualizar su código para guardar agentes.',
            ]);
        }

        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            throw ValidationException::withMessages([
                'slug' => 'La carpeta solo puede llevar minúsculas, números y guiones.',
            ]);
        }

        $definition = $this->definition($repository);

        if ($definition === []) {
            throw ValidationException::withMessages([
                'agent' => 'El archivo no tiene types/agent.yml con los campos del agente.',
            ]);
        }

        $current = $this->open($repository, $slug);

        if ($creating && $current !== null) {
            throw ValidationException::withMessages([
                'slug' => 'Ya hay un agente en esa carpeta.',
            ]);
        }

        if (! $creating && $current === null) {
            throw ValidationException::withMessages([
                'agent' => 'Ese agente ya no está en el archivo.',
            ]);
        }

        $markdown = $this->markdown($definition, $values, $current['extra'] ?? [], $body);
        $problem = $this->rules->violation($repository, $this->path($slug), $markdown);

        if ($problem !== null) {
            throw ValidationException::withMessages(['agent' => $problem]);
        }

        $name = trim((string) ($values['name'] ?? $slug));

        $this->contents->update(
            $repository,
            $this->path($slug),
            $markdown,
            $creating ? "Crea el agente {$name}" : "Actualiza el agente {$name}",
        );

        return $markdown;
    }

    public function path(string $slug): string
    {
        return "agents/{$slug}/agent.md";
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function load(GithubRepository $repository): array
    {
        $file = $this->contents->read($repository, 'types/agent.yml');
        $spec = is_array($file) ? DocumentRules::parse($file['content']) : null;
        $required = is_array($spec) ? ($spec['required'] ?? null) : null;

        if (! is_array($required) || array_is_list($required)) {
            return [];
        }

        $fields = [];

        foreach ($required as $key => $label) {
            if (! is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                continue;
            }

            $fields[] = [
                'key' => $key,
                'label' => is_string($label) && trim($label) !== '' ? trim($label) : $key,
            ];
        }

        return $fields;
    }

    /**
     * @param  list<array{key: string, label: string}>  $definition
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $extra
     */
    private function markdown(array $definition, array $values, array $extra, string $body): string
    {
        $front = ['type' => 'agent'];

        foreach ($definition as $field) {
            $front[$field['key']] = trim((string) ($values[$field['key']] ?? ''));
        }

        foreach ($extra as $key => $value) {
            if (! array_key_exists($key, $front)) {
                $front[$key] = $value;
            }
        }

        $yaml = rtrim(Yaml::dump($front, 2, 2));
        $markdown = rtrim($body);

        return "---\n{$yaml}\n---\n".($markdown === '' ? "\n" : "\n\n{$markdown}\n");
    }
}
