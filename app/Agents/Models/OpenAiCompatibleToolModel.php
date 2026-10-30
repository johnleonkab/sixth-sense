<?php

namespace App\Agents\Models;

use App\Agents\AgentDefinition;
use App\Ai\AiUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * DeepSeek, or any API that speaks OpenAI's chat completions with tools.
 *
 * With reasoning on, DeepSeek runs several rounds of reasoning and tool calls,
 * wants each round's reasoning_content sent back in every later request, and
 * ignores the temperature: the agent's effort is what tunes it.
 */
class OpenAiCompatibleToolModel implements ToolModel
{
    /**
     * @param  array{name: string, key: ?string, base_url: string, thinking: bool}  $config  thinking: the API takes DeepSeek's thinking switch.
     */
    public function __construct(private array $config) {}

    public function turn(AgentDefinition $agent, array $messages, array $tools): ModelTurn
    {
        if (blank($this->config['key'])) {
            throw new AiUnavailable("{$this->config['name']} API key is not set.");
        }

        $models = array_values(array_unique(array_filter([$agent->model, ...$agent->fallbackModels])));

        foreach ($models as $position => $model) {
            $response = $this->request($agent, $model, $messages, $tools);

            // Busy or down: the next model of the chain, if there is one.
            if (in_array($response->status(), [429, 503], true) && $position < count($models) - 1) {
                continue;
            }

            if ($response->failed()) {
                throw new AiUnavailable("{$this->config['name']} {$model} failed with HTTP {$response->status()}: ".($response->json('error.message') ?? ''));
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
        $body = array_filter([
            'model' => $model,
            'messages' => array_map($this->message(...), $messages),
            'tools' => $tools === [] ? null : array_map(fn (array $tool): array => ['type' => 'function', 'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => ($tool['parameters']['properties'] ?? []) === [] ? ['type' => 'object', 'properties' => (object) []] : $tool['parameters'],
            ]], $tools),
            'max_tokens' => $agent->maxTokens,
            ...$this->weights($agent),
        ], fn (mixed $value): bool => $value !== null);

        try {
            return Http::baseUrl(rtrim($this->config['base_url'], '/'))
                ->withToken((string) $this->config['key'])
                ->connectTimeout(5)
                ->timeout($agent->timeout)
                ->acceptJson()
                ->post('/chat/completions', $body);
        } catch (ConnectionException $exception) {
            throw new AiUnavailable("{$this->config['name']} {$model} did not answer: {$exception->getMessage()}", previous: $exception);
        }
    }

    /**
     * The agent's "weights": with reasoning, its effort; without, its temperature.
     *
     * @return array<string, mixed>
     */
    private function weights(AgentDefinition $agent): array
    {
        if (! $this->config['thinking']) {
            return ['temperature' => $agent->temperature];
        }

        return $agent->reasoning
            ? ['thinking' => ['type' => 'enabled'], 'reasoning_effort' => $agent->effort]
            : ['thinking' => ['type' => 'disabled'], 'temperature' => $agent->temperature];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function message(array $message): array
    {
        return match ($message['role']) {
            'assistant' => array_filter([
                'role' => 'assistant',
                'content' => $message['content'] ?? '',
                // DeepSeek needs every round's reasoning back while tools are in play.
                'reasoning_content' => $this->config['thinking'] ? ($message['reasoning'] ?? null) : null,
                'tool_calls' => ($message['tool_calls'] ?? []) === [] ? null : array_map(fn (ToolCall $call): array => [
                    'id' => $call->id,
                    'type' => 'function',
                    'function' => ['name' => $call->name, 'arguments' => json_encode((object) $call->arguments, JSON_UNESCAPED_UNICODE)],
                ], $message['tool_calls']),
            ], fn (mixed $value): bool => $value !== null),
            'tool' => ['role' => 'tool', 'tool_call_id' => $message['tool_call_id'], 'content' => $message['content']],
            default => ['role' => $message['role'], 'content' => $message['content']],
        };
    }

    private function turnFrom(Response $response, string $model): ModelTurn
    {
        $message = $response->json('choices.0.message', []);
        $usage = $response->json('usage', []);

        return new ModelTurn(
            text: trim((string) ($message['content'] ?? '')),
            toolCalls: array_values(array_map(fn (array $call): ToolCall => new ToolCall(
                id: (string) ($call['id'] ?? uniqid('call_')),
                name: (string) ($call['function']['name'] ?? ''),
                arguments: (array) (json_decode((string) ($call['function']['arguments'] ?? '{}'), true) ?: []),
            ), $message['tool_calls'] ?? [])),
            reasoning: $message['reasoning_content'] ?? null,
            model: $model,
            inputTokens: (int) ($usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
            reasoningTokens: (int) ($usage['completion_tokens_details']['reasoning_tokens'] ?? 0),
        );
    }
}
