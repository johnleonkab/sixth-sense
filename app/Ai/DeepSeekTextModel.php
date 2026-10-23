<?php

namespace App\Ai;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * DeepSeek chat completions. JSON mode needs the word "json" and an example
 * in the prompt; the API does not take a schema. An empty body is retried once.
 *
 * @see https://api-docs.deepseek.com/guides/json_mode
 */
class DeepSeekTextModel implements TextModel
{
    /**
     * @param  array{api_key: ?string, base_url: string, model: string, fallback_model?: ?string, timeout: int, max_tokens: int, thinking: string}  $config
     */
    public function __construct(private array $config) {}

    public function json(string $system, string $user, array $schema, float $temperature = 0): array
    {
        if (blank($this->config['api_key'])) {
            throw new RuntimeException('DEEPSEEK_API_KEY is not set.');
        }

        $body = [
            'messages' => [
                ['role' => 'system', 'content' => $this->withSchema($system, $schema)],
                ['role' => 'user', 'content' => $user],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => $temperature,
            'max_tokens' => $this->config['max_tokens'],
            ...$this->thinking(),
        ];

        [$response, $model] = $this->post($body);

        if ($response->failed()) {
            throw new RuntimeException("Text model failed with HTTP {$response->status()}: ".($response->json('error.message') ?? ''));
        }

        $answer = $this->object($response);

        // Thinking spends the token budget before the json. One more try, with
        // that budget left for the answer itself.
        if ($answer === null) {
            $response = $this->request($model, [...$body, ...$this->thinking(false)]);

            if ($response->failed()) {
                throw new RuntimeException("Text model failed with HTTP {$response->status()}: ".($response->json('error.message') ?? ''));
            }

            $answer = $this->object($response);
        }

        if ($answer === null) {
            $cutOff = $response->json('choices.0.finish_reason') === 'length';

            throw new RuntimeException($cutOff
                ? 'Text model returned an unexpected answer: it was cut off.'
                : 'Text model returned an unexpected answer.');
        }

        return $answer;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function withSchema(string $system, array $schema): string
    {
        $example = json_encode($this->example($schema), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $system."\n\nResponde solo con un objeto json, con esta forma:\n{$example}";
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function example(array $schema): mixed
    {
        return match ($schema['type'] ?? 'STRING') {
            'OBJECT' => collect($schema['properties'] ?? [])->map(fn (array $property): mixed => $this->example($property))->all(),
            'ARRAY' => [$this->example($schema['items'] ?? ['type' => 'STRING'])],
            'BOOLEAN' => false,
            'INTEGER', 'NUMBER' => 0,
            'STRING' => isset($schema['enum']) ? (string) $schema['enum'][0] : '',
            default => '',
        };
    }

    /**
     * Thinking is on unless asked otherwise, and then temperature is ignored.
     *
     * @return array<string, mixed>
     */
    private function thinking(bool $enabled = true): array
    {
        if (! $enabled || ($this->config['thinking'] ?? 'disabled') === 'disabled') {
            return [
                'thinking' => ['type' => 'disabled'],
                'reasoning_effort' => 'none',
            ];
        }

        return [
            'thinking' => ['type' => 'enabled'],
            'reasoning_effort' => $this->config['thinking'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: Response, 1: string}
     */
    private function post(array $body): array
    {
        $models = array_values(array_unique(array_filter([$this->config['model'], $this->config['fallback_model'] ?? null])));

        if ($models === []) {
            throw new LogicException('No text model configured.');
        }

        foreach ($models as $position => $model) {
            $response = $this->request($model, $body);

            if (! in_array($response->status(), [429, 503], true) || $position === count($models) - 1) {
                return [$response, $model];
            }
        }

        throw new LogicException('No text model configured.');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function request(string $model, array $body): Response
    {
        return Http::baseUrl($this->config['base_url'])
            ->withToken((string) $this->config['api_key'])
            ->connectTimeout(3)
            ->timeout($this->config['timeout'])
            ->acceptJson()
            ->retry(2, 1500, fn (Throwable $exception): bool => $exception instanceof RequestException
                && in_array($exception->response->status(), [500, 502, 503, 504], true), throw: false)
            ->post('/chat/completions', ['model' => $model, ...$body]);
    }

    private function content(Response $response): string
    {
        return trim((string) $response->json('choices.0.message.content'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function object(Response $response): ?array
    {
        $content = $this->content($response);

        if (preg_match('/```(?:json)?\s*(.*?)```/s', $content, $fenced) === 1) {
            $content = trim($fenced[1]);
        }

        foreach ([$content, $this->sliceObject($content)] as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $answer = json_decode($candidate, true);

            if (is_array($answer)) {
                return $answer;
            }
        }

        return null;
    }

    private function sliceObject(string $content): ?string
    {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return substr($content, $start, $end - $start + 1);
    }
}
