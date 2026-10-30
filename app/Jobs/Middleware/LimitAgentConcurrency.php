<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * At most N runs of one agent at a time, its own configured limit: a busy
 * agent waits its turn instead of taking every worker.
 */
class LimitAgentConcurrency
{
    public function __construct(private string $agent, private int $limit, private int $seconds = 300) {}

    public function handle(object $job, Closure $next): void
    {
        for ($slot = 0; $slot < $this->limit; $slot++) {
            $lock = Cache::lock("agent-slot:{$this->agent}:{$slot}", $this->seconds);

            if ($lock->get()) {
                try {
                    $next($job);
                } finally {
                    $lock->release();
                }

                return;
            }
        }

        $job->release(5);
    }
}
