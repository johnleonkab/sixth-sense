<?php

use App\Comms\Models\Embeddings;
use App\Comms\Models\FakeEmbeddings;
use App\Models\Comms\CommsEmbedding;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('a cached embedding is reused without a second provider call', function () {
    Http::preventStrayRequests();
    config([
        'ai.providers.gemini.api_key' => 'test-key',
        'ai.providers.gemini.base_url' => 'https://generativelanguage.googleapis.com',
        'comms.embeddings.model' => 'gemini-embedding-001',
    ]);
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent' => Http::response([
            'embedding' => ['values' => [0.6, 0.8]],
        ]),
    ]);

    $embeddings = new Embeddings;
    $first = $embeddings->vector('Revisar el flujo de prepago');
    $second = $embeddings->vector('Revisar el flujo de prepago');

    expect($first)->toEqual([0.6, 0.8])
        ->and($second)->toEqual($first)
        ->and(CommsEmbedding::query()->count())->toBe(1)
        ->and(CommsEmbedding::query()->sole()->hash)->toBe(sha1('Revisar el flujo de prepago'));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent'
        && $request->hasHeader('x-goog-api-key', 'test-key')
        && $request['content']['parts'][0]['text'] === 'Revisar el flujo de prepago');
});

test('an embedding failure returns null', function () {
    Http::preventStrayRequests();
    config([
        'ai.providers.gemini.api_key' => 'test-key',
        'ai.providers.gemini.base_url' => 'https://generativelanguage.googleapis.com',
        'comms.embeddings.model' => 'gemini-embedding-001',
    ]);
    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response(['error' => 'down'], 503),
    ]);

    expect((new Embeddings)->vector('Revisar el flujo de prepago'))->toBeNull()
        ->and(CommsEmbedding::query()->count())->toBe(0);
});

test('embedding input is truncated to 8000 characters', function () {
    Http::preventStrayRequests();
    config([
        'ai.providers.gemini.api_key' => 'test-key',
        'ai.providers.gemini.base_url' => 'https://generativelanguage.googleapis.com',
        'comms.embeddings.model' => 'gemini-embedding-001',
    ]);
    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response([
            'embedding' => ['values' => [1]],
        ]),
    ]);

    (new Embeddings)->vector(str_repeat('á', 8001));

    Http::assertSent(fn (Request $request): bool => mb_strlen($request['content']['parts'][0]['text']) === 8000);
});

test('equal texts score 1 and unrelated texts score low', function () {
    $fake = new FakeEmbeddings;
    $left = $fake->vector('Revisar el flujo de prepago');
    $same = $fake->vector('Revisar el flujo de prepago');
    $other = $fake->vector('Parte meteorológico mañana');

    expect($left)->not->toBeNull()
        ->and($same)->not->toBeNull()
        ->and($other)->not->toBeNull()
        ->and(Embeddings::cosine($left, $same))->toEqualWithDelta(1.0, 0.000001)
        ->and(Embeddings::cosine($left, $other))->toBe(0.0);
});
