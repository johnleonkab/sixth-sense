<?php

namespace App\Agents;

use App\Models\AgentRun;
use Illuminate\Support\Collection;

/**
 * What the agents that ran earlier in an ask did, in full: each tool they
 * called with its arguments and result, what they concluded and what they left
 * on screen. The next agent decides from this, not from a summary, so two
 * agents never act on different assumptions.
 */
final class AgentTranscript
{
    /**
     * @param  Collection<int, AgentRun>  $runs
     */
    public static function of(Collection $runs): string
    {
        return $runs->map(function (AgentRun $run): string {
            $lines = ["— {$run->agent}: «{$run->task}»"];

            foreach ($run->steps ?? [] as $step) {
                $lines[] = '  · '.$step['tool'].'('.json_encode($step['arguments'] ?? [], JSON_UNESCAPED_UNICODE).')'
                    .(isset($step['error']) ? " → error: {$step['error']}" : (isset($step['result']) ? " → {$step['result']}" : ''))
                    .(isset($step['production']) ? " [en pantalla: {$step['production']}]" : '');
            }

            $lines[] = $run->status === AgentRun::DONE ? "  Concluyó: {$run->say}" : "  No terminó: {$run->error}";

            return implode("\n", $lines);
        })->implode("\n");
    }
}
