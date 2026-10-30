<?php

namespace App\Agents\Models;

/**
 * A tool a model asked to call, with its arguments.
 */
final readonly class ToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
    ) {}
}
