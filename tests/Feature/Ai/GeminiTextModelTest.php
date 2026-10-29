<?php

use App\Ai\GeminiTextModel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function geminiModel(array $overrides = []): GeminiTextModel
{
    return new GeminiTextModel([
        'api_key' => 'test-key',
        'base_url' => 'https://generativelanguage.googleapis.com',
        'model' => 'gemini-3.8-flash',
        'thinking_level' => 'low',
        'timeout' => 10,
        ...$overrides,
    ]);
}

function geminiUrl(string $model = 'gemini-3.8-flash'): string
{
    return "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
}

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
});

test('it asks gemini for json with the schema and the thinking level', function () {
    Http::fake([
        geminiUrl() => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"important":true}']]]]]]),
    ]);

    $answer = geminiModel()->json('Decide.', 'El email.', ['type' => 'OBJECT', 'required' => ['important']], 0);

    expect($answer)->toBe(['important' => true]);

    Http::assertSent(function (Request $request) {
        return $request->url() === geminiUrl()
            && $request->hasHeader('x-goog-api-key', 'test-key')
            && $request['systemInstruction']['parts'][0]['text'] === 'Decide.'
            && $request['contents'][0]['parts'][0]['text'] === 'El email.'
            && $request['generationConfig']['temperature'] === 0.0
            && $request['generationConfig']['responseMimeType'] === 'application/json'
            && $request['generationConfig']['responseSchema']['required'] === ['important']
            && $request['generationConfig']['thinkingConfig'] === ['thinkingLevel' => 'low'];
    });
});

test('a briefly overloaded model is retried', function () {
    Http::fake([
        geminiUrl() => Http::sequence()
            ->push(['error' => ['message' => 'high demand']], 503)
            ->push(['candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]]]),
    ]);

    expect(geminiModel()->json('Decide.', 'El email.', ['type' => 'OBJECT']))->toBe(['ok' => true]);

    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
});

test('a model out of quota hands over to the fallback model', function () {
    Http::fake([
        geminiUrl('gemini-3.5-flash-lite') => Http::response(['error' => ['message' => 'quota']], 429),
        geminiUrl('gemini-flash-lite-latest') => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]]]),
    ]);

    $answer = geminiModel([
        'model' => 'gemini-3.5-flash-lite',
        'fallback_model' => 'gemini-flash-lite-latest',
    ])->json('Decide.', 'El email.', ['type' => 'OBJECT']);

    expect($answer)->toBe(['ok' => true]);
    Http::assertSentCount(2);
});

test('a response that is not json is an error', function () {
    Http::fake([
        geminiUrl() => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'no sé']]]]]]),
    ]);

    geminiModel()->json('Decide.', 'El email.', ['type' => 'OBJECT']);
})->throws(RuntimeException::class, 'unexpected answer');

test('a failed response is an error', function () {
    Http::fake([
        geminiUrl() => Http::response(['error' => ['message' => 'overloaded']], 500),
    ]);

    geminiModel()->json('Decide.', 'El email.', ['type' => 'OBJECT']);
})->throws(RuntimeException::class, 'HTTP 500');

test('it refuses to call gemini without an api key', function () {
    geminiModel(['api_key' => null])->json('Decide.', 'El email.', ['type' => 'OBJECT']);
})->throws(RuntimeException::class, 'GEMINI_API_KEY is not set.');
