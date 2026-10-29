<?php

namespace App\Ai;

/**
 * JSON Schema, written once for every provider (each driver adapts it).
 */
final class Schema
{
    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>|null  $required  All of them when null.
     * @return array<string, mixed>
     */
    public static function object(array $properties, ?array $required = null): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => $required ?? array_keys($properties)];
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    public static function list(array $items): array
    {
        return ['type' => 'array', 'items' => $items];
    }

    /**
     * @param  list<string>|null  $enum
     * @return array<string, mixed>
     */
    public static function string(?array $enum = null, bool $nullable = false): array
    {
        return array_filter(['type' => 'string', 'enum' => $enum, 'nullable' => $nullable ?: null], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function integer(bool $nullable = false): array
    {
        return $nullable ? ['type' => 'integer', 'nullable' => true] : ['type' => 'integer'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function number(): array
    {
        return ['type' => 'number'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function boolean(): array
    {
        return ['type' => 'boolean'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function strings(): array
    {
        return self::list(self::string());
    }
}
