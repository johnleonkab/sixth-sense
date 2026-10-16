<?php

namespace App\Ai;

interface TextModel
{
    /**
     * Ask for one JSON object matching the schema.
     *
     * Schema types are the ones callers already write: OBJECT, STRING, BOOLEAN,
     * INTEGER, NUMBER and ARRAY, with properties, items, required, enum and nullable.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function json(string $system, string $user, array $schema, float $temperature = 0): array;
}
