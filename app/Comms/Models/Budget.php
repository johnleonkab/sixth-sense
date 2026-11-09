<?php

namespace App\Comms\Models;

use App\Models\AiUsage;
use App\Models\User;

/**
 * Token budgets for one user. A spent budget stops the model call.
 */
class Budget
{
    /**
     * @param  list<int>  $eventIds
     */
    public function exhausted(User $user, array $eventIds, int $runTokens = 0): bool
    {
        if ($runTokens >= (int) config('comms.budgets.per_run')) {
            return true;
        }

        $tasks = array_keys(config('comms.tasks'));

        $day = AiUsage::query()
            ->whereBelongsTo($user)
            ->where('created_at', '>=', now()->startOfDay())
            ->whereIn('task', $tasks)
            ->get(['input_tokens', 'output_tokens'])
            ->sum(fn (AiUsage $usage): int => $usage->input_tokens + $usage->output_tokens);

        if ($day >= (int) config('comms.budgets.per_day')) {
            return true;
        }

        if ($eventIds === []) {
            return false;
        }

        $message = AiUsage::query()
            ->whereBelongsTo($user)
            ->whereIn('task', $tasks)
            ->where(function ($query) use ($eventIds): void {
                foreach ($eventIds as $id) {
                    $query->orWhereJsonContains('event_ids', $id);
                }
            })
            ->get(['input_tokens', 'output_tokens'])
            ->sum(fn (AiUsage $usage): int => $usage->input_tokens + $usage->output_tokens);

        return $message >= (int) config('comms.budgets.per_message');
    }
}
