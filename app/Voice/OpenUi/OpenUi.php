<?php

namespace App\Voice\OpenUi;

/**
 * Views composed from the OpenUI blocks of resources/js/canvas/openui/spec.ts.
 * The prompt the voice follows is generated from those same blocks by
 * `npm run openui:prompt`; this class reads it, and writes OpenUI Lang for
 * views Laravel builds itself.
 */
class OpenUi
{
    /**
     * How to compose a view, as the voice is told.
     */
    public static function prompt(): string
    {
        $path = resource_path('openui/prompt.md');

        return is_file($path) ? trim((string) file_get_contents($path)) : '';
    }

    /**
     * One OpenUI Lang value: a string, number, boolean, null, list or a
     * reference to another statement (Ref).
     */
    public static function value(mixed $value): string
    {
        return match (true) {
            $value instanceof Ref => $value->name,
            is_string($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            is_int($value), is_float($value) => (string) (is_float($value) ? round($value, 2) : $value),
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_array($value) => '['.implode(', ', array_map(self::value(...), $value)).']',
            default => json_encode((string) $value, JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * A block call: Metric("Usuarios", "1.204").
     *
     * @param  list<mixed>  $arguments  Positional, as the block's signature orders them.
     */
    public static function call(string $block, array $arguments): string
    {
        while ($arguments !== [] && end($arguments) === null) {
            array_pop($arguments);
        }

        return $block.'('.implode(', ', array_map(self::value(...), $arguments)).')';
    }
}
