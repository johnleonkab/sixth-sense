<?php

namespace App\Agents\Evals;

use App\Models\AgentProduction;
use App\Models\AgentRun;

/**
 * Scores an agent's run, or the orchestrator's plan, against a case: by the
 * outcome (it finished, it proposed or not, with the right kind of tool, and
 * it never touched what it must not), not by the exact path.
 */
class EvalScorer
{
    /**
     * @return list<string> What went wrong; empty when the case passes.
     */
    public function run(EvalCase $case, AgentRun $run): array
    {
        $used = array_column($run->steps ?? [], 'tool');
        $proposed = $run->productions()->where('kind', AgentProduction::PROPOSAL)->exists();
        $problems = [];

        if ($run->status !== AgentRun::DONE) {
            $problems[] = 'no terminó: '.($run->error ?? $run->status);
        }

        if ($case->anyTool !== [] && array_intersect($case->anyTool, $used) === []) {
            $problems[] = 'no usó ninguna de: '.implode(', ', $case->anyTool);
        }

        if (($touched = array_values(array_intersect($case->noTool, $used))) !== []) {
            $problems[] = 'usó lo que no debía: '.implode(', ', $touched);
        }

        if ($case->proposes === true && ! $proposed) {
            $problems[] = 'no dejó ninguna propuesta';
        }

        if ($case->proposes === false && $proposed) {
            $problems[] = 'dejó una propuesta que no tocaba';
        }

        if ($run->status === AgentRun::DONE && trim((string) $run->say) === '') {
            $problems[] = 'no dijo nada';
        }

        return $problems;
    }

    /**
     * @param  list<array{agent: string, task: string}>  $plan
     * @return list<string>
     */
    public function plan(EvalCase $case, array $plan): array
    {
        $agents = array_column($plan, 'agent');
        $missing = array_values(array_diff($case->agents, $agents));
        $extra = array_values(array_diff($agents, $case->agents));

        return array_filter([
            $missing === [] ? null : 'no lo pasó a: '.implode(', ', $missing),
            $extra === [] ? null : 'lo pasó de más a: '.implode(', ', $extra),
        ]);
    }
}
