<?php

namespace App\Agents\Models;

use App\Agents\AgentDefinition;
use App\Ai\AiUnavailable;
use App\Ai\GeminiSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Gemini's own API with function calling. The model's parts are sent back as
 * they came on every round (their thought signatures with them), the way
 * DeepSeek wants its reasoning back.
 */
class GeminiToolModel implements ToolModel
{
    /**
     * @param  array{key: ?string, base_url: string}  $config
     */
    public function __construct(private array $config) {}

    public function turn(AgentDefinition $agent, array $messages, array $tools): ModelTurn
    {
        if (blank($this->config['key'])) {
            throw new AiUnavailable('Gemini API key is not set.');
        }

        $models = array_values(array_unique(array_filter([$agent->model, ...$agent->fallbackModels])));

        foreach ($models as $position => $model) {
            $response = $this->request($agent, $model, $messages, $tools);

            if (in_array($response->status(), [429, 503], true) && $position < count($models) - 1) {
                continue;
            }

            if ($response->failed()) {
                throw new AiUnavailable("Gemini {$model} failed with HTTP {$response->status()}: ".($response->json('error.message') ?? ''));
            }

            return $this->turnFrom($response, $model);
        }

        throw new AiUnavailable("No model configured for agent {$agent->name}.");
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     */
    private function request(AgentDefinition $agent, string $model, array $messages, array $tools): Response
    {
        $system = implode("\n\n", array_column(array_filter($messages, fn (array $message): bool => $message['role'] === 'system'), 'content'));

        $body = array_filter([
            'systemInstruction' => $system === '' ? null : ['parts' => [['text' => $system]]],
            'contents' => $this->contents($messages),
            'tools' => $tools === [] ? null : [['functionDeclarations' => array_map(fn (array $tool): array => array_filter([
                'name' => $tool['name'],
                'description' => $tool['description'],
                // Gemini rejects objects without properties: tools without arguments declare none.
                'parameters' => ($tool['parameters']['properties'] ?? []) === [] ? null : GeminiSchema::of($tool['parameters']),
            ]), $tools)]],
            'generationConfig' => [
                'maxOutputTokens' => $agent->maxTokens,
                ...($agent->reasoning ? ['thinkingConfig' => ['thinkingLevel' => $agent->effort === 'max' ? 'high' : $agent->effort]] : ['temperature' => $agent->temperature]),
            ],
        ], fn (mixed $value): bool => $value !== null);

        try {
            return Http::baseUrl(rtrim($this->config['base_url'], '/'))
                ->withHeaders(['x-goog-api-key' => (string) $this->config['key']])
                ->connectTimeout(5)
                ->timeout($agent->timeout)
                ->acceptJson()
                ->post("/v1beta/models/{$model}:generateContent", $body);
        } catch (ConnectionException $exception) {
            throw new AiUnavailable("Gemini {$model} did not answer: {$exception->getMessage()}", previous: $exception);
        }
    }

    /**
     * The conversation as Gemini contents: tool results of one round go
     * together, in one user turn.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function contents(array $messages): array
    {
        $contents = [];

        foreach ($messages as $message) {
            $content = match ($message['role']) {
                'user' => ['role' => 'user', 'parts' => [['text' => $message['content']]]],
                'assistant' => ['role' => 'model', 'parts' => $this->modelParts($message)],
                'tool' => ['role' => 'user', 'parts' => [['functionResponse' => ['name' => $message['name'], 'response' => ['content' => json_decode($message['content'], true) ?? $message['content']]]]]],
                default => null,
            };

            if ($content === null) {
                continue;
            }

            $last = array_key_last($contents);

            if ($message['role'] === 'tool' && $last !== null && isset($contents[$last]['parts'][0]['functionResponse'])) {
                $contents[$last]['parts'][] = $content['parts'][0];
            } else {
                $contents[] = $content;
            }
        }

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array<string, mixed>>
     */
    private function modelParts(array $message): array
    {
        $raw = json_decode((string) ($message['reasoning'] ?? ''), true);

        if (is_array($raw) && $raw !== []) {
            return $raw;
        }

        return [
            ...(($message['content'] ?? '') !== '' ? [['text' => $message['content']]] : []),
            ...array_map(fn (ToolCall $call): array => ['functionCall' => ['name' => $call->name, 'args' => (object) $call->arguments]], $message['tool_calls'] ?? []),
        ];
    }

    private function turnFrom(Response $response, string $model): ModelTurn
    {
        $parts = $response->json('candidates.0.content.parts', []);
        $usage = $response->json('usageMetadata', []);
        $calls = [];
        $text = [];

        foreach ($parts as $index => $part) {
            if (isset($part['functionCall'])) {
                $calls[] = new ToolCall("call_{$index}", (string) $part['functionCall']['name'], (array) ($part['functionCall']['args'] ?? []));
            } elseif (isset($part['text']) && ! ($part['thought'] ?? false)) {
                $text[] = $part['text'];
            }
        }

        return new ModelTurn(
            text: trim(implode('', $text)),
            toolCalls: $calls,
            reasoning: json_encode($parts, JSON_UNESCAPED_UNICODE),
            model: $model,
            inputTokens: (int) ($usage['promptTokenCount'] ?? 0),
            // Like DeepSeek's, the output counts the thinking too.
            outputTokens: (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
            reasoningTokens: (int) ($usage['thoughtsTokenCount'] ?? 0),
        );
    }
}
