<?php

namespace App\Voice\Providers;

use App\Models\User;
use App\Voice\AssistantProfile;
use App\Voice\VoiceProvider;
use App\Voice\VoiceProviderUnavailable;
use App\Voice\VoiceSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Gemini Live API. Issues single-use ephemeral tokens with the session setup
 * locked server-side, so the browser never sees the API key.
 *
 * @see https://ai.google.dev/gemini-api/docs/ephemeral-tokens
 */
class GeminiVoiceProvider implements VoiceProvider
{
    /**
     * @param  array{api_key: ?string, api_version: string, base_url: string, model: string, voice: string, voices?: array<string, string>, timeout: int, end_of_speech_sensitivity?: string, silence_duration_ms?: int}  $config
     * @param  array{connect_window: int, max_duration: int}  $session
     */
    public function __construct(
        private array $config,
        private array $session,
    ) {}

    public function startSession(User $user, AssistantProfile $assistant): VoiceSession
    {
        if (blank($this->config['api_key'])) {
            throw VoiceProviderUnavailable::misconfigured('gemini', 'GEMINI_API_KEY is not set.');
        }

        $now = CarbonImmutable::now();
        $connectBefore = $now->addSeconds($this->session['connect_window']);
        $expiresAt = $now->addSeconds($this->session['max_duration']);
        $voice = $this->voiceFor($user);
        $setup = $this->setup($assistant, $voice);

        try {
            $response = Http::baseUrl($this->config['base_url'])
                ->withHeaders(['x-goog-api-key' => $this->config['api_key']])
                ->timeout($this->config['timeout'])
                ->acceptJson()
                ->post("/{$this->config['api_version']}/auth_tokens", [
                    'uses' => 1,
                    'expireTime' => $expiresAt->toIso8601ZuluString(),
                    'newSessionExpireTime' => $connectBefore->toIso8601ZuluString(),
                    'bidiGenerateContentSetup' => $setup,
                ]);
        } catch (ConnectionException $exception) {
            throw VoiceProviderUnavailable::failed('gemini', $exception->getMessage());
        }

        if ($response->failed() || blank($response->json('name'))) {
            throw VoiceProviderUnavailable::failed(
                'gemini',
                "HTTP {$response->status()}: ".($response->json('error.message') ?? 'missing token name'),
            );
        }

        return new VoiceSession(
            provider: 'gemini',
            token: $response->json('name'),
            connectBefore: $connectBefore,
            expiresAt: $expiresAt,
            connection: [
                'model' => $this->config['model'],
                'api_version' => $this->config['api_version'],
                'voice' => $voice,
                'instructions' => $assistant->instructions,
                'tools' => $setup['tools'] ?? [],
                'realtime_input' => $setup['realtimeInputConfig'],
            ],
        );
    }

    /**
     * The voice the person chose, if it is still offered; otherwise the app's.
     */
    private function voiceFor(User $user): string
    {
        return $user->voice !== null && array_key_exists($user->voice, $this->config['voices'] ?? [])
            ? $user->voice
            : $this->config['voice'];
    }

    /**
     * The Live API session setup, locked into the token.
     *
     * @return array<string, mixed>
     */
    private function setup(AssistantProfile $assistant, string $voice): array
    {
        return [
            'model' => "models/{$this->config['model']}",
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'voiceConfig' => [
                        'prebuiltVoiceConfig' => ['voiceName' => $voice],
                    ],
                ],
            ],
            'systemInstruction' => [
                'parts' => [['text' => $assistant->instructions]],
            ],
            'inputAudioTranscription' => (object) [],
            'outputAudioTranscription' => (object) [],
            'realtimeInputConfig' => $this->realtimeInputConfig(),
            ...($assistant->tools === [] ? [] : [
                'tools' => [['functionDeclarations' => $this->functionDeclarations($assistant)]],
            ]),
        ];
    }

    /**
     * When Gemini decides the person stopped talking.
     *
     * @return array{automaticActivityDetection: array<string, string|int>}
     */
    private function realtimeInputConfig(): array
    {
        return ['automaticActivityDetection' => array_filter([
            'endOfSpeechSensitivity' => $this->config['end_of_speech_sensitivity'] ?? null,
            'silenceDurationMs' => $this->config['silence_duration_ms'] ?? null,
        ])];
    }

    /**
     * @return list<array{name: string, description: string, parameters: array<string, mixed>}>
     */
    private function functionDeclarations(AssistantProfile $assistant): array
    {
        return array_map(fn (array $tool): array => array_filter([
            'name' => $tool['name'],
            'description' => $tool['description'],
            // Gemini rejects objects without properties: tools without arguments declare none.
            'parameters' => ($tool['parameters']['properties'] ?? []) === [] ? null : $this->schema($tool['parameters']),
        ]), $assistant->tools);
    }

    /**
     * Gemini expects OpenAPI-style schemas with upper-case types.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function schema(array $schema): array
    {
        if (isset($schema['type']) && is_string($schema['type'])) {
            $schema['type'] = strtoupper($schema['type']);
        }

        if (isset($schema['properties'])) {
            $schema['properties'] = array_map(fn (array $property): array => $this->schema($property), $schema['properties']);
        }

        if (isset($schema['items'])) {
            $schema['items'] = $this->schema($schema['items']);
        }

        return $schema;
    }
}
