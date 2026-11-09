<?php

namespace App\Comms\Models;

/**
 * One structured completion. Tokens stay at zero when the text model does not
 * report them; a test double can set them so budgets are exercisable.
 */
final readonly class ModelCompletion
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public array $body,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $latencyMs = 1,
        public ?float $cost = null,
    ) {}
}
