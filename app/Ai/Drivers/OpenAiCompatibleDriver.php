<?php

namespace App\Ai\Drivers;

use App\Ai\AiUnavailable;
use App\Ai\Answer;
use App\Ai\Driver;
use App\Ai\Prompt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Any API that speaks OpenAI's chat completions: DeepSeek, OpenAI, Groq,
 * OpenRouter, a model served on one's own machine… The base URL says which.
 *
 * JSON answers use "json_object" mode (the one every such API has), with the
 * schema written into the instructions; where "json_schema" is supported it
 * can be enforced instead.
 */
class OpenAiCompatibleDriver implements Driver
{
    /**
     * @param  array{key: ?string, base_url: string, json?: string, reasoning_parameter?: ?string, name?: string}  $config
     *                                                                                                                      json: "object" (default) or "schema" (strict, where supported).
     */
    public function __construct(private array $config) {}

    public function generate(string $model, Prompt $prompt, ?array $schema, array $options): Answer
    {
        $name = $this->config['name'] ?? 'OpenAI-compatible';

        if (blank($this->config['key'])) {
            throw new AiUnavailable("{$name} API key is not set.");
        }

        $strict = ($this->config['json'] ?? 'object') === 'schema';
        $instructions = $prompt->instructions();

        // In json_object mode the schema goes with the instructions: fixed, so it is cached with them.
        if ($schema !== null && ! $strict) {
            $instructions .= "\n\nResponde únicamente con un objeto JSON válido (json) que siga este esquema:\n".json_encode($schema, JSON_UNESCAPED_UNICODE);
        }

        $messages = [['role' => 'system', 'content' => $instructions]];

        foreach ($prompt->history as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt->task];

        try {
            $response = Http::baseUrl(rtrim($this->config['base_url'], '/'))
                ->withToken((string) $this->config['key'])
                ->timeout((int) ($options['timeout'] ?? 30))
                ->acceptJson()
                ->post('/chat/completions', array_filter([
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => $prompt->temperature,
                    'response_format' => match (true) {
                        $schema === null => null,
                        $strict => ['type' => 'json_schema', 'json_schema' => ['name' => 'answer', 'schema' => $schema]],
                        default => ['type' => 'json_object'],
                    },
                    ...(filled($this->config['reasoning_parameter'] ?? null) && filled($options['reasoning'] ?? null)
                        ? [$this->config['reasoning_parameter'] => $options['reasoning']]
                        : []),
                ], fn (mixed $value): bool => $value !== null));
        } catch (ConnectionException $exception) {
            throw new AiUnavailable("{$name} {$model} did not answer: {$exception->getMessage()}", previous: $exception);
        }

        if ($response->failed()) {
            throw new AiUnavailable("{$name} {$model} failed with HTTP {$response->status()}: ".($response->json('error.message') ?? ''));
        }

        $usage = $response->json('usage', []);

        return new Answer(
            text: (string) ($response->json('choices.0.message.content') ?? ''),
            inputTokens: (int) ($usage['prompt_tokens'] ?? 0),
            // DeepSeek says how much of the prompt came from its cache one way, OpenAI another.
            cachedTokens: (int) ($usage['prompt_cache_hit_tokens'] ?? $usage['prompt_tokens_details']['cached_tokens'] ?? 0),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
            reasoningTokens: (int) ($usage['completion_tokens_details']['reasoning_tokens'] ?? 0),
        );
    }
}
