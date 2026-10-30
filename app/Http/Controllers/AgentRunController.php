<?php

namespace App\Http\Controllers;

use App\Models\AgentProduction;
use App\Models\AgentRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where an ask the voice delegated stands, for the screen that follows it:
 * each agent's progress, what they said and what they made to show.
 */
class AgentRunController extends Controller
{
    public function __invoke(Request $request, int $run): JsonResponse
    {
        $run = $request->user()->agentRuns()->whereNull('parent_id')->with('children')->findOrFail($run);
        $refs = $run->refs ?? $run->children->flatMap(fn (AgentRun $child): array => $child->refs ?? [])->all();

        $productions = $request->user()->agentProductions()
            ->whereIn('ref', $refs)
            ->get()
            ->sortBy(fn (AgentProduction $production): int|false => array_search($production->ref, $refs, true))
            ->values();

        return response()->json([
            'status' => $run->status,
            'say' => $run->say,
            'error' => $run->error,
            'agents' => $run->children->map(fn (AgentRun $child): array => [
                'agent' => $child->agent,
                'task' => $child->task,
                'status' => $child->status,
            ])->all(),
            'productions' => $productions->map(fn (AgentProduction $production): array => [
                'ref' => $production->ref,
                'kind' => $production->kind,
                'summary' => $production->summary,
                'canvas' => $production->canvas,
                'beside' => $production->beside,
            ])->all(),
        ]);
    }
}
