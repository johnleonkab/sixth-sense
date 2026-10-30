<?php

namespace App\Agents\Models;

/**
 * One answer of a model in a tool loop: its text, the tools it asked for,
 * its reasoning (sent back on the next round where the provider needs it),
 * and what it took.
 */
final readonly class ModelTurn
{
    /**
     * @param  list<ToolCall>  $toolCalls
     */
    public function __construct(
        public string $text = '',
        public array $toolCalls = [],
        public ?string $reasoning = null,
        public string $model = '',
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $reasoningTokens = 0,
    ) {}
}
