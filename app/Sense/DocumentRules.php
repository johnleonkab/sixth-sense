<?php

namespace App\Sense;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks a file against the rules of its type. The rules live in the
 * repository as types/{type}.yml; this class only applies them.
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

        if ($document === null) {
            return str_starts_with($path, 'agents/') && str_ends_with($path, '/agent.md')
                ? 'Un agente necesita cabecera con name, purpose y when.'
                : null;
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
}
