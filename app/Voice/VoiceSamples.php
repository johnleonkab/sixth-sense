<?php

namespace App\Voice;

use App\Ai\AiGenerationContext;
use App\Ai\AiObservability;
use App\Ai\ProviderKeys;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A short sample of each voice, to hear it before choosing it. Made once
 * with Gemini's text-to-speech and kept, so playing it again costs nothing.
 */
class VoiceSamples
{
    /**
     * @param  array{api_key: ?string, base_url: string, sample_model: string, sample_text: string, timeout: int}  $config
     */
    public function __construct(private array $config, private AiObservability $observability) {}

    /**
     * Where the sample of this voice is kept (on the local disk), made now if it was not yet.
     */
    public function pathFor(string $voice, User $user): string
    {
        $path = "voice-samples/{$this->config['sample_model']}/{$voice}.wav";

        if (! Storage::disk('local')->exists($path)) {
            Storage::disk('local')->put($path, $this->make($voice, AiGenerationContext::forUser($user)));
        }

        return $path;
    }

    private function make(string $voice, AiGenerationContext $context): string
    {
        $key = app(ProviderKeys::class)->own('gemini') ?? $this->config['api_key'];

        if (blank($key)) {
            throw new RuntimeException('GEMINI_API_KEY is not set.');
        }

        $input = strtr($this->config['sample_text'], [':voice' => $voice]);
        $startedAt = microtime(true);
        $response = Http::baseUrl($this->config['base_url'])
            ->withHeaders(['x-goog-api-key' => $key])
            ->timeout(max($this->config['timeout'], 30))
            ->acceptJson()
            ->post("/v1beta/models/{$this->config['sample_model']}:generateContent", [
                'contents' => [['parts' => [['text' => $input]]]],
                'generationConfig' => [
                    'responseModalities' => ['AUDIO'],
                    'speechConfig' => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $voice]]],
                ],
            ]);

        $this->observability->capture($context, 'gemini', $this->config['sample_model'], [
            ['role' => 'user', 'content' => $input],
        ], null, [
            'input_tokens' => $response->json('usageMetadata.promptTokenCount'),
            'output_tokens' => $response->json('usageMetadata.candidatesTokenCount'),
            'status' => $response->status(),
            'stop_reason' => $response->json('candidates.0.finishReason'),
            'latency' => microtime(true) - $startedAt,
        ]);

        $audio = $response->json('candidates.0.content.parts.0.inlineData');

        if ($response->failed() || ! is_array($audio) || blank($audio['data'] ?? null)) {
            throw new RuntimeException("Gemini could not make a sample of {$voice}: HTTP {$response->status()} ".($response->json('error.message') ?? ''));
        }

        $bytes = (string) base64_decode($audio['data'], true);

        // Raw PCM ("audio/L16;codec=pcm;rate=24000") is wrapped so any browser plays it.
        return str_starts_with((string) ($audio['mimeType'] ?? ''), 'audio/L16')
            ? self::wav($bytes, preg_match('/rate=(\d+)/', $audio['mimeType'], $rate) ? (int) $rate[1] : 24000)
            : $bytes;
    }

    /**
     * 16-bit mono PCM as a WAV file.
     */
    private static function wav(string $pcm, int $rate): string
    {
        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVE'
            .'fmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
            .'data'.pack('V', strlen($pcm)).$pcm;
    }
}
