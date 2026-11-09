<?php

namespace App\Comms\Models;

/**
 * Checks a decoded JSON value against the schemas this app already writes:
 * object, array, string, integer, number and boolean, with enum and nullable.
 */
class SchemaCheck
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function passes(array $schema, mixed $value): bool
    {
        if (($schema['nullable'] ?? false) === true && $value === null) {
            return true;
        }

        return match ($schema['type'] ?? null) {
            'object' => is_array($value) && ! array_is_list($value) && $this->objectPasses($schema, $value),
            'array' => is_array($value) && array_is_list($value) && $this->listPasses($schema, $value),
            'string' => is_string($value) && $this->enumPasses($schema, $value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $value
     */
    private function objectPasses(array $schema, array $value): bool
    {
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? array_keys($properties);

        foreach ($required as $key) {
            if (! array_key_exists($key, $value)) {
                return false;
            }
        }

        foreach ($properties as $key => $property) {
            if (array_key_exists($key, $value) && ! $this->passes($property, $value[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<mixed>  $value
     */
    private function listPasses(array $schema, array $value): bool
    {
        $items = $schema['items'] ?? ['type' => 'string'];

        foreach ($value as $item) {
            if (! $this->passes($items, $item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function enumPasses(array $schema, string $value): bool
    {
        $enum = $schema['enum'] ?? null;

        return ! is_array($enum) || in_array($value, $enum, true);
    }
}
