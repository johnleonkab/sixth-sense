<?php

namespace App\Scenes;

use App\Ai\AiGenerationContext;
use App\Ai\AiObservability;
use App\Ai\DeepSeekTextModel;
use App\Ai\ProviderKeys;
use InvalidArgumentException;

/**
 * Scene writing goes to DeepSeek even when AI_TEXT_PROVIDER is Gemini.
 * Voice stays on the live API; this class never builds a Gemini model.
 */
class DeepSeekSceneText implements SceneText
{
    public function __construct(private AiObservability $observability) {}

    public function capable(string $system, string $user, array $schema, ?AiGenerationContext $context = null): array
    {
        return $this->model(true)->json($system, $user, $schema, context: $context);
    }

    public function light(string $system, string $user, array $schema, ?AiGenerationContext $context = null): array
    {
        return $this->model(false)->json($system, $user, $schema, context: $context);
    }

    /**
     * Built per call so a long-lived worker does not keep another person's key.
     */
    private function model(bool $capable): DeepSeekTextModel
    {
        $config = config('ai.providers.deepseek');

        if (! is_array($config)) {
            throw new InvalidArgumentException('DeepSeek is not configured.');
        }

        return new DeepSeekTextModel([
            'api_key' => app(ProviderKeys::class)->own('deepseek') ?? $config['api_key'],
            'base_url' => $config['base_url'],
            'model' => $capable ? $config['model'] : $config['light_model'],
            'fallback_model' => $config['fallback_model'],
            'timeout' => $config['timeout'],
            'max_tokens' => $capable ? $config['max_tokens'] : $config['light_max_tokens'],
            'thinking' => $capable ? $config['thinking'] : 'disabled',
        ], $this->observability);
    }
}
