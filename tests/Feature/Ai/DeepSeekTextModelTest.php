<?php

use App\Ai\DeepSeekTextModel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function deepSeekModel(array $overrides = []): DeepSeekTextModel
{
    return new DeepSeekTextModel([
        'api_key' => 'test-key',
        'base_url' => 'https://api.deepseek.com',
        'model' => 'deepseek-v4-pro',
        'timeout' => 10,
        'max_tokens' => 4096,
        'thinking' => 'low',
        ...$overrides,
    ]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
});

test('it asks deepseek for json and shows the shape in the prompt', function () {
    Http::fake([
        'https://api.deepseek.com/chat/completions' => Http::response(['choices' => [['message' => ['content' => '{"important":false}']]]]),
    ]);

    $answer = deepSeekModel(['thinking' => 'disabled', 'max_tokens' => 1024])->json(
        'Decide.',
        'El email.',
        ['type' => 'OBJECT', 'properties' => ['important' => ['type' => 'BOOLEAN']], 'required' => ['important']],
        0,
    );

    expect($answer)->toBe(['important' => false]);

    Http::assertSent(function (Request $request) {
        $system = $request['messages'][0]['content'];

        return $request->url() === 'https://api.deepseek.com/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request['model'] === 'deepseek-v4-pro'
            && $request['messages'][1]['content'] === 'El email.'
            && $request['response_format'] === ['type' => 'json_object']
            && $request['temperature'] === 0.0
            && $request['max_tokens'] === 1024
            && $request['thinking'] === ['type' => 'disabled']
            && $request['reasoning_effort'] === 'none'
            && str_contains($system, 'json')
            && str_contains($system, '"important"');
    });
});

test('a capable call keeps a little thinking on', function () {
    Http::fake([
        'https://api.deepseek.com/chat/completions' => Http::response(['choices' => [['message' => ['content' => '{"ok":true}']]]]),
    ]);

    deepSeekModel()->json('Decide.', 'El email.', ['type' => 'OBJECT']);

    Http::assertSent(fn (Request $request) => $request['thinking'] === ['type' => 'enabled']
        && $request['reasoning_effort'] === 'low');
});

test('json wrapped in prose is still read', function () {
    Http::fake([
        'https://api.deepseek.com/chat/completions' => Http::response(['choices' => [['message' => ['content' => "Aquí va:\n{\"ok\":true}\nlisto"]]]]),
    ]);

    expect(deepSeekModel()->json('Decide.', 'El email.', ['type' => 'OBJECT']))->toBe(['ok' => true]);
    Http::assertSentCount(1);
});

test('a broken json answer is asked again without thinking', function () {
    Http::fake([
        'https://api.deepseek.com/chat/completions' => Http::sequence()
            ->push(['choices' => [['finish_reason' => 'length', 'message' => ['content' => '{"ok":']]]])
            ->push(['choices' => [['message' => ['content' => '{"ok":true}']]]]),
    ]);

    expect(deepSeekModel()->json('Decide.', 'El email.', ['type' => 'OBJECT']))->toBe(['ok' => true]);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request['thinking'] === ['type' => 'disabled']
        && $request['reasoning_effort'] === 'none');
});

test('an empty json body is asked for once more', function () {
    Http::fake([
        'https://api.deepseek.com/chat/completions' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => '']]]])
            ->push(['choices' => [['message' => ['content' => '{"ok":true}']]]]),
    ]);

    expect(deepSeekModel()->json('Decide.', 'El email.', ['type' => 'OBJECT']))->toBe(['ok' => true]);
    Http::assertSentCount(2);
});

test('a fenced json answer is read', function () {
    Http::fake([
        'https://api.deepseek.com/chat/completions' => Http::response(['choices' => [['message' => ['content' => "```json\n{\"ok\":true}\n```"]]]]),
    ]);

    expect(deepSeekModel()->json('Decide.', 'El email.', ['type' => 'OBJECT']))->toBe(['ok' => true]);
});

test('a model out of quota hands over to the fallback model', function () {
    Http::fake([
        'https://api.deepseek.com/chat/completions' => Http::sequence()
            ->push(['error' => ['message' => 'quota']], 429)
            ->push(['choices' => [['message' => ['content' => '{"ok":true}']]]]),
    ]);

    $answer = deepSeekModel([
        'model' => 'deepseek-v4-pro',
        'fallback_model' => 'deepseek-flash',
    ])->json('Decide.', 'El email.', ['type' => 'OBJECT']);

    expect($answer)->toBe(['ok' => true]);
    Http::assertSent(fn (Request $request) => $request['model'] === 'deepseek-flash');
});

test('a briefly overloaded model is retried', function () {
    Http::fake([
        'https://api.deepseek.com/chat/completions' => Http::sequence()
            ->push(['error' => ['message' => 'high demand']], 503)
            ->push(['choices' => [['message' => ['content' => '{"ok":true}']]]]),
    ]);

    expect(deepSeekModel()->json('Decide.', 'El email.', ['type' => 'OBJECT']))->toBe(['ok' => true]);
    Sleep::assertSleptTimes(1);
});

test('it refuses to call deepseek without an api key', function () {
    deepSeekModel(['api_key' => ''])->json('Decide.', 'El email.', ['type' => 'OBJECT']);
})->throws(RuntimeException::class, 'DEEPSEEK_API_KEY is not set.');
