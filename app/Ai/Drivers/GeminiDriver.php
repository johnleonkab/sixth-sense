<?php

namespace App\Ai\Drivers;

use App\Ai\AiUnavailable;
use App\Ai\Answer;
use App\Ai\Driver;
use App\Ai\Prompt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Google's Gemini API (generateContent), with its structured output.
 *
 * @see https://ai.google.dev/gemini-api/docs/structured-output
 */
class GeminiDriver implements Driver
{
    /**
     * @param  array{key: ?string, base_url: string}  $config
     */
    public function __construct(private array $config) {}

    public function generate(string $model, Prompt $prompt, ?array $schema, array $options): Answer
    {
        if (blank($this->config['key'])) {
            throw new AiUnavailable('GEMINI_API_KEY is not set.');
        }

        $contents = array_map(fn (array $turn): array => ['role' => $turn['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $turn['content']]]], $prompt->history);
        $contents[] = ['role' => 'user', 'parts' => [['text' => $prompt->task]]];

        try {
            $response = Http::baseUrl($this->config['base_url'])
                ->withHeaders(['x-goog-api-key' => $this->config['key']])
                ->timeout((int) ($options['timeout'] ?? 30))
                ->acceptJson()
                ->post("/v1beta/models/{$model}:generateContent", [
                    'systemInstruction' => ['parts' => [['text' => $prompt->instructions()]]],
                    'contents' => $contents,
                    'generationConfig' => array_filter([
                        'temperature' => $prompt->temperature,
                        'responseMimeType' => $schema ? 'application/json' : null,
                        'responseSchema' => $schema ? self::geminiSchema($schema) : null,
                        'thinkingConfig' => filled($options['reasoning'] ?? null) ? ['thinkingLevel' => $options['reasoning']] : null,
                    ], fn (mixed $value): bool => $value !== null),
                ]);
        } catch (ConnectionException $exception) {
            throw new AiUnavailable("Gemini {$model} did not answer: {$exception->getMessage()}", previous: $exception);
        }

        if ($response->failed()) {
            throw new AiUnavailable("Gemini {$model} failed with HTTP {$response->status()}: ".($response->json('error.message') ?? ''));
        }

        $usage = $response->json('usageMetadata', []);

        return new Answer(
            text: (string) (collect($response->json('candidates.0.content.parts', []))->last()['text'] ?? ''),
            inputTokens: (int) ($usage['promptTokenCount'] ?? 0),
            cachedTokens: (int) ($usage['cachedContentTokenCount'] ?? 0),
            outputTokens: (int) ($usage['candidatesTokenCount'] ?? 0),
            reasoningTokens: (int) ($usage['thoughtsTokenCount'] ?? 0),
        );
    }

    /**
     * Gemini's flavour of JSON Schema: types in capitals.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function geminiSchema(array $schema): array
    {
        $converted = [];

        foreach ($schema as $key => $value) {
            $converted[$key] = match (true) {
                $key === 'type' => strtoupper((string) $value),
                $key === 'properties' => array_map(fn (array $property): array => self::geminiSchema($property), $value),
                $key === 'items' => self::geminiSchema($value),
                default => $value,
            };
        }

        return $converted;
    }
}
