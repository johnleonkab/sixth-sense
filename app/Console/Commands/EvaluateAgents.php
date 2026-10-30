<?php

namespace App\Console\Commands;

use App\Agents\AgentRegistry;
use App\Agents\AgentRunner;
use App\Agents\Evals\EvalCase;
use App\Agents\Evals\EvalScorer;
use App\Agents\Orchestrator;
use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Runs an agent's evaluation set against its real model, with a person's real
 * connections, and scores each case by outcome. Run it before changing an
 * agent's model, and again after, to compare. Every proposal it leaves still
 * waits for a "sí": nothing is sent.
 */
class EvaluateAgents extends Command
{
    protected $signature = 'agents:eval {agent : comunicacion, agenda, investigacion, redaccion, integraciones, vistas u orquestador} {--user= : id de la persona cuyas conexiones se usan} {--limit=0 : solo los N primeros casos}';

    protected $description = 'Scores an agent against its evaluation set (database/evals), with its configured model';

    public function handle(AgentRegistry $registry, AgentRunner $runner, Orchestrator $orchestrator, EvalScorer $scorer): int
    {
        $name = (string) $this->argument('agent');
        $cases = EvalCase::for($name);
        $user = $this->option('user') ? User::query()->find($this->option('user')) : User::query()->orderBy('id')->first();

        if ($cases === [] || $user === null) {
            $this->error($cases === [] ? "No hay casos para «{$name}»." : 'No hay ninguna persona con la que evaluar.');

            return self::FAILURE;
        }

        if ((int) $this->option('limit') > 0) {
            $cases = array_slice($cases, 0, (int) $this->option('limit'));
        }

        $agent = $name === 'orquestador' ? null : $registry->find($user, $name);
        $rows = [];
        $passed = 0;

        foreach ($cases as $case) {
            if ($agent === null) {
                $problems = $scorer->plan($case, $orchestrator->plan($user, $case->ask));
            } else {
                $run = $user->agentRuns()->create(['agent' => $name, 'task' => $case->ask, 'status' => AgentRun::QUEUED, 'plan' => ['eval' => true]]);
                $problems = $scorer->run($case, $runner->run($agent, $user, $run));
            }

            $passed += $problems === [] ? 1 : 0;
            $rows[] = [mb_strimwidth($case->ask, 0, 70, '…'), $problems === [] ? 'ok' : implode('; ', $problems)];
        }

        $this->table(['Caso', 'Resultado'], $rows);
        $this->info(sprintf('%s: %d de %d (%d %%)', $name, $passed, count($cases), (int) round($passed / count($cases) * 100)));

        return self::SUCCESS;
    }
}
