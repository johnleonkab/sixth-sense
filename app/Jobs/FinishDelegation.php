<?php

namespace App\Jobs;

use App\Models\AgentRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Gathers an ask: what each agent said, in order, and every reference they
 * left to show. The voice reads the `say`; the screen shows the references.
 */
class FinishDelegation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $runId)
    {
        $this->onQueue('agents-light');
    }

    public function handle(): void
    {
        $run = AgentRun::query()->with('children')->find($this->runId);

        if ($run === null || $run->finished()) {
            return;
        }

        $children = $run->children;
        $done = $children->where('status', AgentRun::DONE);
        $failed = $children->where('status', AgentRun::FAILED);

        $run->update([
            'status' => $done->isEmpty() ? AgentRun::FAILED : AgentRun::DONE,
            'say' => Str::limit($done->pluck('say')->filter()->implode(' '), 800) ?: null,
            'refs' => $children->flatMap(fn (AgentRun $child): array => $child->refs ?? [])->values()->all(),
            'error' => $failed->isEmpty() ? null : $failed->pluck('error')->filter()->implode(' '),
            'input_tokens' => $children->sum('input_tokens'),
            'output_tokens' => $children->sum('output_tokens'),
            'reasoning_tokens' => $children->sum('reasoning_tokens'),
            'cost_usd' => $children->whereNotNull('cost_usd')->isEmpty() ? null : $children->sum('cost_usd'),
            'latency_ms' => $run->started_at ? (int) $run->started_at->diffInMilliseconds(now()) : null,
            'finished_at' => now(),
        ]);
    }
}
