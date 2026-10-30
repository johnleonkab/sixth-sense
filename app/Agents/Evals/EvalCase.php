<?php

namespace App\Agents\Evals;

/**
 * One real ask for an agent and what a good outcome looks like: the tools it
 * must have used (any of them), the ones it must never use, whether it ends
 * with a proposal waiting for the "sí", and — for the orchestrator — which
 * agents it hands the ask to. Scored by outcome, not by the path taken.
 */
final readonly class EvalCase
{
    /**
     * @param  list<string>  $anyTool
     * @param  list<string>  $noTool
     * @param  list<string>  $agents
     */
    public function __construct(
        public string $ask,
        public array $anyTool = [],
        public array $noTool = [],
        public ?bool $proposes = null,
        public array $agents = [],
    ) {}

    /**
     * @param  array{ask: string, any_tool?: list<string>, no_tool?: list<string>, proposes?: bool, agents?: list<string>}  $case
     */
    public static function fromArray(array $case): self
    {
        return new self(
            ask: $case['ask'],
            anyTool: $case['any_tool'] ?? [],
            noTool: $case['no_tool'] ?? [],
            proposes: $case['proposes'] ?? null,
            agents: $case['agents'] ?? [],
        );
    }

    /**
     * The cases of one agent, from database/evals/{agent}.php.
     *
     * @return list<self>
     */
    public static function for(string $agent): array
    {
        $path = config('agents.evals_path')."/{$agent}.php";

        return is_file($path) ? array_map(self::fromArray(...), require $path) : [];
    }
}
