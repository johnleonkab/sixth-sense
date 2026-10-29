<?php

namespace App\Ai;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Gemini generateContent, with structured output and a fallback model.
 */
class GeminiTextModel implements TextModel
{
    /**
     * @param  array{api_key: ?string, base_url: string, model: string, fallback_model?: ?string, thinking_level?: ?string, timeout: int}  $config
     */
    public function __construct(private array $config, private ?AiObservability $observability = null) {}

    public function json(string $system, string $user, array $schema, float $temperature = 0, ?AiGenerationContext $context = null): array
    {
        if (blank($this->config['api_key'])) {
            throw new RuntimeException('GEMINI_API_KEY is not set.');
        }

        $generation = [
            'temperature' => $temperature,
            'responseMimeType' => 'application/json',
            'responseSchema' => $schema,
        ];

        if (filled($this->config['thinking_level'] ?? null)) {
            $generation['thinkingConfig'] = ['thinkingLevel' => $this->config['thinking_level']];
        }

        $input = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
        $response = $this->post([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
            'generationConfig' => $generation,
        ], $context, $input, $temperature);

        if ($response->failed()) {
            throw new RuntimeException("Text model failed with HTTP {$response->status()}: ".($response->json('error.message') ?? ''));
        }

        $answer = json_decode((string) (collect($response->json('candidates.0.content.parts', []))->last()['text'] ?? ''), true);

        if (! is_array($answer)) {
            throw new RuntimeException('Text model returned an unexpected answer.');
        }

        return $answer;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(array $body, ?AiGenerationContext $context, array $input, float $temperature): Response
    {
        $models = array_values(array_unique(array_filter([$this->config['model'], $this->config['fallback_model'] ?? null])));

        if ($models === []) {
            throw new LogicException('No text model configured.');
        }

        foreach ($models as $position => $model) {
            $response = $this->request($model, $body, $context, $input, $temperature);

            if (! in_array($response->status(), [429, 503], true) || $position === count($models) - 1) {
                return $response;
            }
        }

        throw new LogicException('No text model configured.');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function request(string $model, array $body, ?AiGenerationContext $context, array $input, float $temperature): Response
    {
        $startedAt = microtime(true);
        $response = Http::baseUrl($this->config['base_url'])
            ->withHeaders(['x-goog-api-key' => $this->config['api_key']])
            ->connectTimeout(3)
            ->timeout($this->config['timeout'])
            ->acceptJson()
            ->retry(2, 1500, fn (Throwable $exception): bool => $exception instanceof RequestException
                && in_array($exception->response->status(), [500, 502, 503, 504], true), throw: false)
            ->post("/v1beta/models/{$model}:generateContent", $body);

        if ($context !== null && $this->observability !== null) {
            $output = collect($response->json('candidates.0.content.parts', []))
                ->pluck('text')->filter()->implode("\n");
            $this->observability->capture($context, 'gemini', $model, $input, $output !== '' ? $output : null, [
                'input_tokens' => $response->json('usageMetadata.promptTokenCount'),
                'output_tokens' => $response->json('usageMetadata.candidatesTokenCount'),
                'status' => $response->status(),
                'stop_reason' => $response->json('candidates.0.finishReason'),
                'latency' => microtime(true) - $startedAt,
                'temperature' => $temperature,
            ]);
        }

        return $response;
    }
}
