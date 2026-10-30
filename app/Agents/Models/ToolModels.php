<?php

namespace App\Agents\Models;

use App\Agents\AgentDefinition;
use App\Ai\ProviderKeys;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The tool model each agent runs on, from its provider: DeepSeek through the
 * OpenAI-compatible API, Gemini through its own, or the scripted fake in tests.
 */
class ToolModels
{
    public function __construct(private Container $container, private ProviderKeys $keys) {}

    public function for(AgentDefinition $agent): ToolModel
    {
        if (config('agents.driver') === 'fake') {
            return $this->container->make(FakeToolModel::class);
        }

        $config = config("ai.providers.{$agent->provider}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Unsupported agent provider [{$agent->provider}].");
        }

        $key = $this->keys->own($agent->provider) ?? $config['api_key'];

        return match ($agent->provider) {
            'deepseek' => new OpenAiCompatibleToolModel(['name' => 'DeepSeek', 'key' => $key, 'base_url' => $config['base_url'], 'thinking' => true]),
            'gemini' => new GeminiToolModel(['key' => $key, 'base_url' => $config['base_url']]),
            default => throw new InvalidArgumentException("Unsupported agent provider [{$agent->provider}]."),
        };
    }
}
