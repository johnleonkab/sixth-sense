<?php

use App\Agents\AgentDefinition;
use App\Agents\Models\GeminiToolModel;
use App\Agents\Models\OpenAiCompatibleToolModel;
use App\Agents\Models\ToolCall;
use App\Ai\AiUnavailable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function agentFor(array $overrides = []): AgentDefinition
{
    return AgentDefinition::fromConfig('comunicacion', [...config('agents.agents.comunicacion'), 'model' => 'deepseek-v4-pro', 'fallback_models' => ['deepseek-flash'], ...$overrides]);
}

$tools = [['name' => 'list_alerts', 'description' => 'Avisos.', 'parameters' => ['type' => 'object', 'properties' => ['status' => ['type' => 'string']]]]];
$conversation = [
    ['role' => 'system', 'content' => 'Eres Comunicación.'],
    ['role' => 'user', 'content' => 'Contesta a Marta'],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [new ToolCall('call_1', 'list_alerts', ['status' => 'pending'])], 'reasoning' => 'Miro los avisos.'],
    ['role' => 'tool', 'tool_call_id' => 'call_1', 'name' => 'list_alerts', 'content' => '{"alerts":[]}'],
];

test('DeepSeek gets the tools, its effort instead of a temperature, and every round\'s reasoning back', function () use ($tools, $conversation) {
    Http::fake(['api.deepseek.test/*' => Http::response([
        'choices' => [['message' => ['content' => '', 'reasoning_content' => 'Ahora respondo.', 'tool_calls' => [['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'finish', 'arguments' => '{"say":"Listo."}']]]]]],
        'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30, 'completion_tokens_details' => ['reasoning_tokens' => 12]],
    ])]);

    $turn = (new OpenAiCompatibleToolModel(['name' => 'DeepSeek', 'key' => 'k', 'base_url' => 'https://api.deepseek.test', 'thinking' => true]))->turn(agentFor(), $conversation, $tools);

    expect($turn->toolCalls[0]->name)->toBe('finish')
        ->and($turn->toolCalls[0]->arguments)->toBe(['say' => 'Listo.'])
        ->and($turn->reasoning)->toBe('Ahora respondo.')
        ->and([$turn->inputTokens, $turn->outputTokens, $turn->reasoningTokens])->toBe([120, 30, 12]);

    Http::assertSent(fn (Request $request): bool => $request['thinking'] === ['type' => 'enabled']
        && $request['reasoning_effort'] === 'low'
        && ! isset($request['temperature'])
        && $request['tools'][0]['function']['name'] === 'list_alerts'
        && $request['messages'][2]['reasoning_content'] === 'Miro los avisos.'
        && $request['messages'][2]['tool_calls'][0]['function']['arguments'] === '{"status":"pending"}'
        && $request['messages'][3] === ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"alerts":[]}']);
});

test('without reasoning the temperature counts, and a busy model hands over to the next of its chain', function () use ($tools, $conversation) {
    Http::fakeSequence('api.deepseek.test/*')
        ->push(['error' => ['message' => 'busy']], 429)
        ->push(['choices' => [['message' => ['content' => 'Hecho.']]], 'usage' => []]);

    $turn = (new OpenAiCompatibleToolModel(['name' => 'DeepSeek', 'key' => 'k', 'base_url' => 'https://api.deepseek.test', 'thinking' => true]))
        ->turn(agentFor(['reasoning' => false, 'temperature' => 0.4]), $conversation, $tools);

    expect($turn->text)->toBe('Hecho.')->and($turn->model)->toBe('deepseek-flash');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request['model'] === 'deepseek-flash' && $request['thinking'] === ['type' => 'disabled'] && $request['temperature'] === 0.4);
});

test('a model that fails, or has no key, says so instead of answering', function () use ($tools, $conversation) {
    Http::fake(['api.deepseek.test/*' => Http::response(['error' => ['message' => 'bad']], 500)]);

    expect(fn () => (new OpenAiCompatibleToolModel(['name' => 'DeepSeek', 'key' => 'k', 'base_url' => 'https://api.deepseek.test', 'thinking' => true]))->turn(agentFor(), $conversation, $tools))
        ->toThrow(AiUnavailable::class, 'failed with HTTP 500')
        ->and(fn () => (new OpenAiCompatibleToolModel(['name' => 'DeepSeek', 'key' => null, 'base_url' => 'https://api.deepseek.test', 'thinking' => true]))->turn(agentFor(), $conversation, $tools))
        ->toThrow(AiUnavailable::class, 'key is not set');
});

test('Gemini gets function declarations in its schema format, the tool results together, and its own parts back', function () use ($tools, $conversation) {
    Http::fake(['gemini.test/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => 'Pienso…', 'thought' => true], ['functionCall' => ['name' => 'finish', 'args' => ['say' => 'Listo.']]]]]]],
        'usageMetadata' => ['promptTokenCount' => 50, 'candidatesTokenCount' => 10, 'thoughtsTokenCount' => 5],
    ])]);

    $turn = (new GeminiToolModel(['key' => 'k', 'base_url' => 'https://gemini.test']))->turn(agentFor(['provider' => 'gemini', 'model' => 'gemini-x', 'fallback_models' => []]), $conversation, $tools);

    expect($turn->toolCalls[0]->arguments)->toBe(['say' => 'Listo.'])
        ->and($turn->text)->toBe('')
        ->and($turn->outputTokens)->toBe(15)
        ->and(json_decode($turn->reasoning, true)[1]['functionCall']['name'])->toBe('finish');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1beta/models/gemini-x:generateContent')
        && $request->header('x-goog-api-key') === ['k']
        && $request['systemInstruction']['parts'][0]['text'] === 'Eres Comunicación.'
        && $request['tools'][0]['functionDeclarations'][0]['parameters']['type'] === 'OBJECT'
        && $request['contents'][1]['parts'][0]['functionCall']['name'] === 'list_alerts'
        && $request['contents'][2]['parts'][0]['functionResponse'] === ['name' => 'list_alerts', 'response' => ['content' => ['alerts' => []]]]
        && $request['generationConfig']['thinkingConfig'] === ['thinkingLevel' => 'low']);
});
