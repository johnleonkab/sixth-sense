<?php

namespace App\Comms\Evaluation;

use App\Models\Comms\CommsEvalRun;
use App\Models\User;

/**
 * Compares the latest weekly run with the one before it. A higher share of
 * doubt, refutations, moved links, or corrections is worse. Every other
 * rate is worse when it falls. A missing rate is not a change.
 */
class PromptChange
{
    /** @var list<string> */
    private const LOWER_IS_BETTER = [
        'uncertain_share',
        'uncertain_moved_share',
        'critic_refutation_rate',
        'corrections_per_100',
    ];

    /**
     * @return list<string> Metric names that got worse.
     */
    public function worse(?User $user): array
    {
        $runs = CommsEvalRun::query()
            ->when($user !== null, fn ($query) => $query->whereBelongsTo($user))
            ->where('kind', 'weekly')
            ->orderByDesc('id')
            ->limit(2)
            ->get();

        if ($runs->count() < 2) {
            return [];
        }

        $current = is_array($runs[0]->metrics) ? $runs[0]->metrics : [];
        $previous = is_array($runs[1]->metrics) ? $runs[1]->metrics : [];
        $worse = [];

        foreach (WeeklyEval::METRICS as $metric) {
            if ($this->regressed($metric, $current[$metric] ?? null, $previous[$metric] ?? null)) {
                $worse[] = $metric;
            }
        }

        return $worse;
    }

    private function regressed(string $metric, mixed $current, mixed $previous): bool
    {
        if (! is_numeric($current) || ! is_numeric($previous)) {
            return false;
        }

        $current = (float) $current;
        $previous = (float) $previous;

        return in_array($metric, self::LOWER_IS_BETTER, true)
            ? $current > $previous
            : $current < $previous;
    }
}
