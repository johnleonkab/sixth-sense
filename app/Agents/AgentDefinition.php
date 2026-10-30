<?php

namespace App\Agents;

/**
 * One atomic agent as configured: what it is for, the only tools it may use,
 * and its model and "weights" (with reasoning, the effort; without it, the
 * temperature), limits, queue and budget.
 */
final readonly class AgentDefinition
{
    /**
     * @param  list<string>  $tools
     * @param  list<string>  $fallbackModels
     * @param  list<string>  $routes  Words that send an ask straight here, without the orchestrator's model.
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public string $instructions,
        public array $tools,
        public string $provider,
        public string $model,
        public array $fallbackModels = [],
        public bool $reasoning = false,
        public string $effort = 'low',
        public float $temperature = 0.2,
        public int $maxSteps = 8,
        public int $timeout = 90,
        public int $maxTokens = 4096,
        public string $queue = 'agents-heavy',
        public int $concurrency = 1,
        public ?float $budgetRun = null,
        public ?float $budgetDay = null,
        public bool $parallel = false,
        public array $routes = [],
    ) {}

    /**
     * From an entry of config/agents.php.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        $provider = (string) ($config['provider'] ?? null ?: config('agents.provider'));

        return new self(
            name: $name,
            label: (string) ($config['label'] ?? $name),
            description: (string) ($config['description'] ?? ''),
            instructions: (string) ($config['instructions'] ?? ''),
            tools: array_values($config['tools'] ?? []),
            provider: $provider,
            model: (string) ($config['model'] ?? null ?: config("agents.tiers.{$provider}.".($config['tier'] ?? 'capable'))),
            fallbackModels: array_values($config['fallback_models'] ?? []),
            reasoning: (bool) ($config['reasoning'] ?? false),
            effort: (string) ($config['effort'] ?? 'low'),
            temperature: (float) ($config['temperature'] ?? 0.2),
            maxSteps: (int) ($config['max_steps'] ?? 8),
            timeout: (int) ($config['timeout'] ?? 90),
            maxTokens: (int) ($config['max_tokens'] ?? 4096),
            queue: (string) ($config['queue'] ?? 'agents-heavy'),
            concurrency: max(1, (int) ($config['concurrency'] ?? 1)),
            budgetRun: isset($config['budget_run']) ? (float) $config['budget_run'] : null,
            budgetDay: isset($config['budget_day']) ? (float) $config['budget_day'] : null,
            parallel: (bool) ($config['parallel'] ?? false),
            routes: array_values($config['routes'] ?? []),
        );
    }

    /**
     * Whether the agent only gathers and never proposes a change: only those
     * run side by side; the ones that write go one after another.
     */
    public function readsOnly(): bool
    {
        return $this->parallel;
    }
}
