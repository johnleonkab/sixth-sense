<?php

namespace App\Jobs;

use App\Agents\AgentRegistry;
use App\Agents\AgentRunner;
use App\Agents\AgentTranscript;
use App\Jobs\Middleware\LimitAgentConcurrency;
use App\Models\AgentRun;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One agent of an ask, on its own queue and within its own concurrency limit.
 * It gets the full record of the agents that finished before it in the ask.
 */
class RunAgentStep implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $timeout = 200;

    private string $agent;

    private int $limit;

    public function __construct(public int $runId)
    {
        $run = AgentRun::query()->with('user')->findOrFail($runId);
        $definition = app(AgentRegistry::class)->find($run->user, $run->agent);
        $this->agent = $run->agent;
        $this->limit = $definition->concurrency ?? 1;
        $this->onQueue($definition->queue ?? 'agents-heavy');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new LimitAgentConcurrency($this->agent, $this->limit)];
    }

    public function handle(AgentRegistry $registry, AgentRunner $runner): void
    {
        $run = AgentRun::query()->with(['user', 'parent'])->find($this->runId);

        if ($run === null || $run->status !== AgentRun::QUEUED) {
            return;
        }

        $agent = $registry->find($run->user, $run->agent);

        if ($agent === null) {
            $run->update(['status' => AgentRun::FAILED, 'error' => "No existe el agente «{$run->agent}».", 'finished_at' => now()]);

            return;
        }

        $earlier = $run->parent?->children()->where('position', '<', $run->position)->whereIn('status', [AgentRun::DONE, AgentRun::FAILED])->get() ?? collect();

        try {
            $runner->run($agent, $run->user, $run, AgentTranscript::of($earlier), $run->parent->plan['timezone'] ?? config('agenda.timezone'));
        } catch (Throwable $exception) {
            report($exception);
            $run->update(['status' => AgentRun::FAILED, 'error' => 'El agente ha fallado.', 'finished_at' => now()]);
        }
    }
}
