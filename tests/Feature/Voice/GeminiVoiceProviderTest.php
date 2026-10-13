<?php

use App\Models\User;
use App\Voice\AssistantProfile;
use App\Voice\Providers\GeminiVoiceProvider;
use App\Voice\VoiceProviderUnavailable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function geminiProvider(array $overrides = []): GeminiVoiceProvider
{
    return new GeminiVoiceProvider(
        [
            'api_key' => 'test-key',
            'api_version' => 'v1alpha',
            'base_url' => 'https://generativelanguage.googleapis.com',
            'model' => 'gemini-3.8-live',
            'voice' => 'Kore',
            'timeout' => 5,
            'end_of_speech_sensitivity' => 'END_SENSITIVITY_LOW',
            'silence_duration_ms' => 800,
            ...$overrides,
        ],
        ['connect_window' => 60, 'max_duration' => 1800],
    );
}

beforeEach(function () {
    $this->freezeTime();
    $this->user = User::factory()->make(['name' => 'John León']);
    $this->assistant = new AssistantProfile('Habla en español.');
});

test('it issues a single-use ephemeral token with the session locked server-side', function () {
    Http::fake([
        'generativelanguage.googleapis.com/v1alpha/auth_tokens' => Http::response(['name' => 'auth_tokens/abc123']),
    ]);

    $session = geminiProvider()->startSession($this->user, $this->assistant);

    expect($session->provider)->toBe('gemini')
        ->and($session->token)->toBe('auth_tokens/abc123')
        ->and($session->connectBefore->equalTo(now()->addMinute()))->toBeTrue()
        ->and($session->expiresAt->equalTo(now()->addMinutes(30)))->toBeTrue()
        ->and($session->connection)->toMatchArray([
            'model' => 'gemini-3.8-live',
            'api_version' => 'v1alpha',
            'voice' => 'Kore',
            'instructions' => 'Habla en español.',
        ]);

    Http::assertSent(fn (Request $request) => $request->hasHeader('x-goog-api-key', 'test-key')
        && $request['uses'] === 1
        && $request['newSessionExpireTime'] === now()->addMinute()->toIso8601ZuluString()
        && $request['expireTime'] === now()->addMinutes(30)->toIso8601ZuluString()
        && $request['bidiGenerateContentSetup']['model'] === 'models/gemini-3.8-live'
        && $request['bidiGenerateContentSetup']['generationConfig']['responseModalities'] === ['AUDIO']
        && $request['bidiGenerateContentSetup']['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Kore'
        && $request['bidiGenerateContentSetup']['systemInstruction']['parts'][0]['text'] === 'Habla en español.'
        && array_key_exists('inputAudioTranscription', $request['bidiGenerateContentSetup'])
        && array_key_exists('outputAudioTranscription', $request['bidiGenerateContentSetup'])
        && $request['bidiGenerateContentSetup']['realtimeInputConfig'] === ['automaticActivityDetection' => ['endOfSpeechSensitivity' => 'END_SENSITIVITY_LOW', 'silenceDurationMs' => 800]]);
});

test('it speaks with the voice the person chose, while it is still offered', function (?string $chosen, string $used) {
    Http::fake(['generativelanguage.googleapis.com/v1alpha/auth_tokens' => Http::response(['name' => 'auth_tokens/abc123'])]);
    $this->user->voice = $chosen;

    $session = geminiProvider(['voices' => ['Kore' => 'Firme', 'Puck' => 'Animada']])->startSession($this->user, $this->assistant);

    expect($session->connection['voice'])->toBe($used);
    Http::assertSent(fn (Request $request) => $request['bidiGenerateContentSetup']['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === $used);
})->with([
    'chosen' => ['Puck', 'Puck'],
    'none chosen' => [null, 'Kore'],
    'no longer offered' => ['Darth', 'Kore'],
]);

test('it never sends a request without an api key', function () {
    Http::fake();

    expect(fn () => geminiProvider(['api_key' => null])->startSession($this->user, $this->assistant))
        ->toThrow(VoiceProviderUnavailable::class, 'GEMINI_API_KEY is not set.');

    Http::assertNothingSent();
});

test('it reports errors returned by gemini', function () {
    Http::fake([
        '*' => Http::response(['error' => ['message' => 'API key not valid.']], 400),
    ]);

    expect(fn () => geminiProvider()->startSession($this->user, $this->assistant))
        ->toThrow(VoiceProviderUnavailable::class, 'HTTP 400: API key not valid.');
});

test('it reports connection failures', function () {
    Http::fake([
        '*' => Http::failedConnection(),
    ]);

    expect(fn () => geminiProvider()->startSession($this->user, $this->assistant))
        ->toThrow(VoiceProviderUnavailable::class);
});
