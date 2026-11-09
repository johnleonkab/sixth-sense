<?php

namespace App\Comms\Models;

use App\Ai\TextModelFactory;

/**
 * Runs a communications tier through the shared text-model factory.
 * Prompts are not sent to observability. Usage is recorded by the router.
 */
class TextModelCompleter implements CompletesTasks
{
    public function __construct(private TextModelFactory $models) {}

    public function complete(string $provider, string $tier, string $system, string $input, array $schema): ModelCompletion
    {
        $aiTier = (string) config("comms.ai_tiers.{$tier}", 'capable');
        $override = $tier === 'large' ? config('comms.large_model') : null;
        $override = filled($override) ? (string) $override : null;
        $started = hrtime(true);
        $body = $this->models->make($aiTier, model: $override, provider: $provider)->json($system, $input, $schema);
        $latency = (int) ((hrtime(true) - $started) / 1_000_000);

        return new ModelCompletion(
            body: $body,
            model: $this->models->name($aiTier, $override, $provider),
            latencyMs: max(1, $latency),
        );
    }
}
