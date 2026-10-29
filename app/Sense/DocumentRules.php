<?php

namespace App\Sense;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks a file against the rules of its type. The rules live in the
 * repository as types/{type}.yml; this class only applies them. Agents in
 * .claude/agents/ follow Claude Code's rules instead.
 */
class DocumentRules
{
    /**
     * A repository-relative path, or null when it could leave the repository.
     */
    public static function path(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        if ($path === '' || str_contains($path, '..') || preg_match('#^[\p{L}\p{N}._/-]+$#u', $path) !== 1) {
            return null;
        }

        return $path;
    }

    /**
     * Placeholders a type uses in its folder or its file name.
     *
     * @return list<'project'|'slug'|'date'>
     */
    public static function slots(string $where, string $name): array
    {
        $found = [];

        if (preg_match_all('/\{(project|slug|date)\}/', $where.' '.$name, $matches) === false) {
            return [];
        }

        foreach ($matches[1] as $slot) {
            $found[$slot] = true;
        }

        return array_values(array_filter(
            ['project', 'slug', 'date'],
            fn (string $slot): bool => isset($found[$slot]),
        ));
    }

    /**
     * Why these slots cannot fill the type, or null when they can.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, string>  $slots
     */
    public static function slotProblem(array $spec, array $slots): ?string
    {
        $where = $spec['where'] ?? null;
        $name = $spec['name'] ?? null;

        if (! is_string($where) || ! is_string($name)) {
            return 'Las reglas del tipo no dicen dónde va el archivo.';
        }

        foreach (self::slots($where, $name) as $slot) {
            if (! self::slotValue($slot, $slots[$slot] ?? '')) {
                return match ($slot) {
                    'project' => 'El proyecto va en minúsculas, números y guiones.',
                    'slug' => 'El nombre va en minúsculas, números y guiones.',
                    'date' => 'La fecha tiene que ser AAAA-MM-DD.',
                };
            }
        }

        return null;
    }

    /**
     * The path a type and its slots produce, or null when they do not fit.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, string>  $slots
     */
    public static function locate(array $spec, array $slots): ?string
    {
        if (self::slotProblem($spec, $slots) !== null) {
            return null;
        }

        $where = self::fill((string) $spec['where'], $slots);
        $name = self::fill((string) $spec['name'], $slots);

        if ($where === null || $name === null || $name === '' || str_contains($name, '/')) {
            return null;
        }

        return self::path($where === '' ? $name : $where.'/'.$name);
    }

    /**
     * A typed markdown document. Optional fields are left out when blank.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, string>  $fields
     */
    public static function compose(string $type, array $spec, array $fields, string $body): string
    {
        $document = ['type' => $type];

        foreach (['required', 'optional'] as $group) {
            $keys = $spec[$group] ?? null;

            if (! is_array($keys)) {
                continue;
            }

            foreach ($keys as $key => $label) {
                if (! is_string($key) || $key === '' || $key === 'type') {
                    continue;
                }

                $value = trim($fields[$key] ?? '');

                if ($value === '' && $group === 'optional') {
                    continue;
                }

                $document[$key] = $value;
            }
        }

        $yaml = trim(Yaml::dump($document, 2, 2));
        $body = trim($body);

        return "---\n{$yaml}\n---\n\n".($body === '' ? '' : $body."\n");
    }

    /**
     * Why this file cannot be written, or null when it follows the rules.
     *
     * @param  array<string, mixed>|null  $spec  The parsed types/{type}.yml, when the file declares a type.
     */
    public static function violation(string $path, string $contents, ?array $spec): ?string
    {
        if (preg_match('#^types/[a-z0-9-]+\.yml$#', $path) === 1) {
            return self::typeFile($path, $contents);
        }

        $document = self::document($contents);

        if (ClaudeLayout::isAgent($path)) {
            return self::agentFile($document);
        }

        if ($document === null) {
            return null;
        }

        if (isset($document['_invalid'])) {
            return 'La cabecera YAML no se puede leer.';
        }

        $type = $document['type'] ?? null;

        if (! is_string($type) || $type === '') {
            return null;
        }

        if ($spec === null) {
            return "No hay reglas para el tipo «{$type}». Crea types/{$type}.yml antes.";
        }

        $specType = $spec['type'] ?? null;

        if ($specType !== $type) {
            return "types/{$type}.yml no describe el tipo «{$type}».";
        }

        $where = self::place($spec['where'] ?? null, dirname($path) === '.' ? '' : dirname($path));

        if ($where !== null) {
            return $where;
        }

        $name = self::place($spec['name'] ?? null, basename($path), 'El nombre');

        if ($name !== null) {
            return $name;
        }

        return self::required($spec['required'] ?? null, $document);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function parse(string $yaml): ?array
    {
        try {
            $parsed = Yaml::parse($yaml);
        } catch (ParseException) {
            return null;
        }

        return is_array($parsed) ? $parsed : null;
    }

    /**
     * The front matter of a markdown file, or null when it has none.
     * `_invalid` is set when the block is there but cannot be read.
     *
     * @return array<string, mixed>|null
     */
    public static function document(string $contents): ?array
    {
        if (preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $contents, $matches) !== 1) {
            return null;
        }

        $parsed = self::parse($matches[1]);

        if ($parsed === null) {
            return ['_invalid' => true];
        }

        $parsed['_body'] = $matches[2];

        return $parsed;
    }

    /**
     * A Claude Code subagent: `name` and `description` are required, and the
     * name cannot contain a colon.
     *
     * @param  array<string, mixed>|null  $document
     */
    private static function agentFile(?array $document): ?string
    {
        if ($document === null) {
            return 'Un agente necesita cabecera YAML con name y description.';
        }

        if (isset($document['_invalid'])) {
            return 'La cabecera YAML no se puede leer.';
        }

        foreach (['name', 'description'] as $key) {
            if (! is_string($document[$key] ?? null) || trim($document[$key]) === '') {
                return "Un agente necesita {$key} en la cabecera.";
            }
        }

        if (str_contains($document['name'], ':')) {
            return 'El name de un agente no puede llevar dos puntos.';
        }

        return null;
    }

    private static function typeFile(string $path, string $contents): ?string
    {
        $parsed = self::parse($contents);
        $expected = basename($path, '.yml');

        if ($parsed === null) {
            return "{$path} no es un YAML válido.";
        }

        foreach (['type', 'where', 'name'] as $key) {
            if (! is_string($parsed[$key] ?? null) || trim($parsed[$key]) === '') {
                return "{$path} necesita {$key}.";
            }
        }

        if ($parsed['type'] !== $expected) {
            return "{$path} dice que el tipo es «{$parsed['type']}», y el archivo se llama {$expected}.";
        }

        $required = $parsed['required'] ?? null;

        if (! is_array($required) || $required === [] || array_is_list($required)) {
            return "{$path} necesita required con las claves que el documento debe traer.";
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function required(mixed $required, array $document): ?string
    {
        if (! is_array($required)) {
            return 'Las reglas del tipo no dicen qué claves son obligatorias.';
        }

        foreach ($required as $key => $description) {
            if (! is_string($key)) {
                continue;
            }

            $value = $document[$key] ?? null;
            $text = is_string($value) || is_numeric($value) ? trim((string) $value) : '';

            if ($text === '') {
                $because = is_string($description) && $description !== '' ? " ({$description})" : '';

                return "Falta {$key}{$because}.";
            }
        }

        return null;
    }

    /**
     * @return ?string A sentence when the value does not match the pattern.
     */
    private static function place(mixed $pattern, string $value, string $what = 'La carpeta'): ?string
    {
        if (! is_string($pattern) || $pattern === '') {
            return 'Las reglas del tipo no dicen dónde va el archivo.';
        }

        $regex = preg_quote($pattern, '#');
        $regex = str_replace(
            ['\{project\}', '\{slug\}', '\{date\}'],
            ['[^/]+', '[^/]+', '\\d{4}-\\d{2}-\\d{2}'],
            $regex,
        );

        if (preg_match('#^'.$regex.'$#', $value) !== 1) {
            return "{$what} tiene que ser {$pattern}.";
        }

        return null;
    }

    /**
     * Replace {project}, {slug} and {date}. Unknown placeholders stay put.
     *
     * @param  array<string, string>  $slots
     */
    private static function fill(string $pattern, array $slots): ?string
    {
        $value = preg_replace_callback('/\{(project|slug|date)\}/', function (array $match) use ($slots): string {
            return $slots[$match[1]] ?? $match[0];
        }, $pattern);

        return is_string($value) ? $value : null;
    }

    private static function slotValue(string $slot, string $value): bool
    {
        if ($slot === 'date') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                return false;
            }

            return checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
        }

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1;
    }
}
