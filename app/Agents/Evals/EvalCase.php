<?php

namespace App\Agents\Evals;

use App\Models\LearnedEvalCase;

/**
 * One real ask for an agent and what a good outcome looks like: the tools it
 * must have used (any of them), the ones it must never use, whether it ends
 * with a proposal waiting for the "sí", and — for the orchestrator — which
 * agents it hands the ask to. For the action classifier, the ask is the
 * message and the case says which kinds of action it asks for, and whether a
 * good classifier would be sure. Scored by outcome, not by the path taken.
 */
final readonly class EvalCase
{
    /**
     * @param  list<string>  $anyTool
     * @param  list<string>  $noTool
     * @param  list<string>  $agents
     * @param  ?list<string>  $classes  The kinds of action the message asks for (call, task, question, reply).
     * @param  ?string  $confidence  sure or doubt, for the calls and tasks; null when either is fine.
     */
    public function __construct(
        public string $ask,
        public array $anyTool = [],
        public array $noTool = [],
        public ?bool $proposes = null,
        public array $agents = [],
        public ?array $classes = null,
        public ?string $confidence = null,
        public string $from = 'Marta Ruiz',
    ) {}

    /**
     * @param  array{ask: string, any_tool?: list<string>, no_tool?: list<string>, proposes?: bool, agents?: list<string>, classes?: list<string>, confidence?: string, from?: string}  $case
     */
    public static function fromArray(array $case): self
    {
        return new self(
            ask: $case['ask'],
            anyTool: $case['any_tool'] ?? [],
            noTool: $case['no_tool'] ?? [],
            proposes: $case['proposes'] ?? null,
            agents: $case['agents'] ?? [],
            classes: $case['classes'] ?? null,
            confidence: $case['confidence'] ?? null,
            from: $case['from'] ?? 'Marta Ruiz',
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

    /**
     * The written cases, and those learned from the person's corrections.
     *
     * @return list<self>
     */
    public static function withLearned(string $agent): array
    {
        return [...self::for($agent), ...LearnedEvalCase::query()->where('agent', $agent)->oldest('id')->pluck('case')->map(self::fromArray(...))->all()];
    }
}
