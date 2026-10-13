<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

/**
 * For events that only keep an open screen in step. If the live connection
 * (Reverb) can not take it, the failure is reported and the work that sent
 * it goes on: the screen catches up on its next resync.
 */
trait BroadcastsWhenItCan
{
    use Dispatchable;

    /**
     * @return array<int, mixed>|null
     */
    public static function dispatch(mixed ...$arguments): ?array
    {
        try {
            return event(new static(...$arguments));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
