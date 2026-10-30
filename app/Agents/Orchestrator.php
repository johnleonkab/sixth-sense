<?php

namespace App\Agents;

use App\Agents\Models\ToolModels;
use App\Ai\AiUnavailable;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Decides which agents an ask needs, and in what order. The cheap way first:
 * an agent named by the voice, or the only one whose words appear in the ask,
 * gets it directly. Otherwise a light model plans it. Agents that only read go
 * first and side by side; the ones that write follow, one after another.
 */
class Orchestrator
{
    /** Agents in one ask, at most. */
    private const MAX_STEPS = 4;

    public function __construct(private AgentRegistry $registry, private ToolModels $models) {}

    /**
     * @return list<array{agent: string, task: string}>
     */
    public function plan(User $user, string $task, ?string $hint = null): array
    {
        if ($hint !== null && $hint !== 'orquestador' && $this->registry->find($user, $hint) !== null) {
            return [['agent' => $hint, 'task' => $task]];
        }

        $workers = $this->registry->workers($user);
        $routed = $this->routed($workers, $task);

        if ($routed !== null) {
            return [['agent' => $routed, 'task' => $task]];
        }

        return $this->ordered($workers, $this->planned($user, $workers, $task) ?? [['agent' => 'investigacion', 'task' => $task]]);
    }

    /**
     * The only agent whose route words appear in the ask, if exactly one does.
     *
     * @param  list<AgentDefinition>  $workers
     */
    private function routed(array $workers, string $task): ?string
    {
        $ask = ' '.Str::lower($task).' ';
        $matching = array_filter($workers, fn (AgentDefinition $agent): bool => collect($agent->routes)
            ->contains(fn (string $word): bool => preg_match('/(?<!\pL)'.preg_quote(Str::lower($word), '/').'(?!\pL)/u', $ask) === 1));

        return count($matching) === 1 ? array_values($matching)[0]->name : null;
    }

    /**
     * The plan a light model writes, or null when it can not.
     *
     * @param  list<AgentDefinition>  $workers
     * @return list<array{agent: string, task: string}>|null
     */
    private function planned(User $user, array $workers, string $task): ?array
    {
        $orchestrator = $this->registry->orchestrator();
        $names = array_map(fn (AgentDefinition $agent): string => $agent->name, $workers);
        $catalog = implode("\n", array_map(fn (AgentDefinition $agent): string => "- {$agent->name}: {$agent->description}", $workers));

        try {
            $turn = $this->models->for($orchestrator)->turn($orchestrator, [
                ['role' => 'system', 'content' => "Repartes los encargos de {$user->name} entre estos agentes:\n{$catalog}\n\n"
                    .'Llama a plan con los pasos: cada uno, un agente y lo que tiene que hacer, en una frase. Los menos posibles: uno si basta. '
                    .'Investigación primero si hace falta contexto; luego los que proponen cambios, en el orden en que dependen unos de otros.'],
                ['role' => 'user', 'content' => $task],
            ], [[
                'name' => 'plan',
                'description' => 'Los pasos del encargo.',
                'parameters' => ['type' => 'object', 'properties' => ['steps' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['agent' => ['type' => 'string', 'enum' => $names], 'task' => ['type' => 'string']],
                    'required' => ['agent', 'task'],
                ]]], 'required' => ['steps']],
            ]]);
        } catch (AiUnavailable $exception) {
            report($exception);

            return null;
        }

        $call = collect($turn->toolCalls)->firstWhere('name', 'plan');
        $steps = array_values(array_filter(
            (array) ($call?->arguments['steps'] ?? []),
            fn (mixed $step): bool => is_array($step) && in_array($step['agent'] ?? null, $names, true) && filled($step['task'] ?? null),
        ));

        return $steps === [] ? null : array_map(fn (array $step): array => ['agent' => $step['agent'], 'task' => (string) $step['task']], array_slice($steps, 0, self::MAX_STEPS));
    }

    /**
     * Readers first (they run side by side), then writers in the planned order.
     *
     * @param  list<AgentDefinition>  $workers
     * @param  list<array{agent: string, task: string}>  $steps
     * @return list<array{agent: string, task: string}>
     */
    private function ordered(array $workers, array $steps): array
    {
        $readers = array_map(fn (AgentDefinition $agent): string => $agent->name, array_filter($workers, fn (AgentDefinition $agent): bool => $agent->readsOnly()));

        return [
            ...array_values(array_filter($steps, fn (array $step): bool => in_array($step['agent'], $readers, true))),
            ...array_values(array_filter($steps, fn (array $step): bool => ! in_array($step['agent'], $readers, true))),
        ];
    }
}
