<?php

namespace App\Agents\Evals;

use App\Alerts\Actions\ClassifiedAction;
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

    /**
     * The classifier's answer: the kinds it should find (a reply is never
     * required nor counted as extra, it goes with almost everything), and,
     * for calls and tasks, whether it was as sure as it should be.
     *
     * @param  list<ClassifiedAction>  $actions
     * @return list<string>
     */
    public function classification(EvalCase $case, array $actions): array
    {
        $expected = array_values(array_diff($case->classes ?? [], [ClassifiedAction::REPLY]));
        $found = array_values(array_unique(array_diff(array_map(fn (ClassifiedAction $action): string => $action->class, $actions), [ClassifiedAction::REPLY])));
        $missing = array_values(array_diff($expected, $found));
        $extra = array_values(array_diff($found, $expected));
        $scheduled = array_filter($actions, fn (ClassifiedAction $action): bool => in_array($action->class, [ClassifiedAction::CALL, ClassifiedAction::TASK], true));
        $wrongConfidence = $case->confidence !== null && $scheduled !== [] && collect($scheduled)->contains(fn (ClassifiedAction $action): bool => $action->confidence !== $case->confidence);

        return array_values(array_filter([
            $missing === [] ? null : 'no vio: '.implode(', ', $missing),
            $extra === [] ? null : 'vio de más: '.implode(', ', $extra),
            $wrongConfidence ? 'debía estar '.($case->confidence === ClassifiedAction::SURE ? 'seguro' : 'en duda') : null,
        ]));
    }
}
