<?php

namespace App\Ai;

/**
 * One way of talking to models: a provider's own API, or an OpenAI-compatible one.
 */
interface Driver
{
    /**
     * @param  array<string, mixed>|null  $schema  A JSON Schema the answer must follow, or null for text.
     * @param  array<string, mixed>  $options  timeout (seconds), reasoning (low, medium, high).
     *
     * @throws AiUnavailable when this model can not answer now (worth trying another).
     */
    public function generate(string $model, Prompt $prompt, ?array $schema, array $options): Answer;
}
