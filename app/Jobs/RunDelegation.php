<?php

namespace App\Jobs;

use App\Agents\AgentRegistry;
use App\Agents\Orchestrator;
use App\Models\AgentRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * An ask the voice delegated: the orchestrator plans it, then the agents run
 * as a chain — the ones that only read side by side in a batch, the ones that
 * write one after another — and a last job gathers what they said and made.
 */
class RunDelegation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $runId)
    {
        $this->onQueue('agents-light');
    }

    public function handle(Orchestrator $orchestrator, AgentRegistry $registry): void
    {
        $run = AgentRun::query()->with('user')->find($this->runId);

        if ($run === null || $run->status !== AgentRun::QUEUED) {
            return;
        }

        $run->update(['status' => AgentRun::RUNNING, 'started_at' => now()]);

        try {
            $steps = $orchestrator->plan($run->user, $run->task, $run->plan['hint'] ?? null);
        } catch (Throwable $exception) {
            report($exception);
            $run->update(['status' => AgentRun::FAILED, 'error' => 'No he podido repartir el encargo.', 'finished_at' => now()]);

            return;
        }

        $run->update(['plan' => [...($run->plan ?? []), 'steps' => $steps]]);

        $readers = [];
        $writers = [];

        foreach ($steps as $position => $step) {
            $agent = $registry->find($run->user, $step['agent']);
            $child = $run->children()->create([
                'user_id' => $run->user_id,
                'agent' => $step['agent'],
                'position' => $position,
                'task' => $step['task'],
                'status' => AgentRun::QUEUED,
            ]);

            $job = new RunAgentStep($child->id);

            if ($agent?->readsOnly()) {
                $readers[] = $job;
            } else {
                $writers[] = $job;
            }
        }

        Bus::chain([
            ...($readers === [] ? [] : [Bus::batch($readers)->allowFailures()->onQueue('agents-heavy')]),
            ...$writers,
            new FinishDelegation($run->id),
        ])->dispatch();
    }
}
