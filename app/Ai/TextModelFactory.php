<?php

namespace App\Ai;

use InvalidArgumentException;

/**
 * Builds the text model for a tier. Communications and the existing pipelines
 * share this so a tier always resolves the same way.
 */
class TextModelFactory
{
    public function __construct(private ProviderKeys $keys, private AiObservability $observability) {}

    public function make(string $tier, ?int $timeout = null, ?string $model = null, ?string $provider = null): TextModel
    {
        $provider ??= (string) config('ai.text');
        $config = $this->config($provider);

        $options = [
            'api_key' => $this->keys->own($provider) ?? $config['api_key'],
            'base_url' => $config['base_url'],
            'model' => $this->name($tier, $model, $provider),
            'fallback_model' => $config['fallback_model'],
            'timeout' => $timeout ?? $config['timeout'],
        ];

        if ($provider === 'deepseek') {
            return new DeepSeekTextModel([
                ...$options,
                'max_tokens' => $tier === 'light' ? $config['light_max_tokens'] : $config['max_tokens'],
                'thinking' => $tier === 'light' ? 'disabled' : $config['thinking'],
            ], $this->observability);
        }

        if ($provider === 'gemini') {
            return new GeminiTextModel([
                ...$options,
                'thinking_level' => $config['thinking_level'],
            ], $this->observability);
        }

        throw new InvalidArgumentException("Unsupported text provider [{$provider}].");
    }

    public function name(string $tier, ?string $model, string $provider): string
    {
        if (filled($model)) {
            return $model;
        }

        $config = $this->config($provider);

        return $tier === 'light' ? (string) $config['light_model'] : (string) $config['model'];
    }

    /**
     * @return array<string, mixed>
     */
    private function config(string $provider): array
    {
        $config = config("ai.providers.{$provider}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Unsupported text provider [{$provider}].");
        }

        return $config;
    }
}
