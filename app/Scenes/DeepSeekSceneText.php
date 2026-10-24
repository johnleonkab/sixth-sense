<?php

namespace App\Scenes;

use App\Ai\DeepSeekTextModel;
use InvalidArgumentException;

/**
 * Scene writing goes to DeepSeek even when AI_TEXT_PROVIDER is Gemini.
 * Voice stays on the live API; this class never builds a Gemini model.
 */
class DeepSeekSceneText implements SceneText
{
    private DeepSeekTextModel $capableModel;

    private DeepSeekTextModel $lightModel;

    public function __construct()
    {
        $config = config('ai.providers.deepseek');

        if (! is_array($config)) {
            throw new InvalidArgumentException('DeepSeek is not configured.');
        }

        $this->capableModel = new DeepSeekTextModel([
            'api_key' => $config['api_key'],
            'base_url' => $config['base_url'],
            'model' => $config['model'],
            'fallback_model' => $config['fallback_model'],
            'timeout' => $config['timeout'],
            'max_tokens' => $config['max_tokens'],
            'thinking' => $config['thinking'],
        ]);

        $this->lightModel = new DeepSeekTextModel([
            'api_key' => $config['api_key'],
            'base_url' => $config['base_url'],
            'model' => $config['light_model'],
            'fallback_model' => $config['fallback_model'],
            'timeout' => $config['timeout'],
            'max_tokens' => $config['light_max_tokens'],
            'thinking' => 'disabled',
        ]);
    }

    public function capable(string $system, string $user, array $schema): array
    {
        return $this->capableModel->json($system, $user, $schema);
    }

    public function light(string $system, string $user, array $schema): array
    {
        return $this->lightModel->json($system, $user, $schema);
    }
}
