<?php

namespace App\Ai;

/**
 * Gemini expects OpenAPI-style schemas with upper-case types.
 */
final class GeminiSchema
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function of(array $schema): array
    {
        if (isset($schema['type']) && is_string($schema['type'])) {
            $schema['type'] = strtoupper($schema['type']);
        }

        if (isset($schema['properties'])) {
            $schema['properties'] = array_map(fn (array $property): array => self::of($property), $schema['properties']);
        }

        if (isset($schema['items'])) {
            $schema['items'] = self::of($schema['items']);
        }

        return $schema;
    }
}
