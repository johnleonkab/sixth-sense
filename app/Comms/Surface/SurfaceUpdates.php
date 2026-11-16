<?php

namespace App\Comms\Surface;

use App\Events\HomeCountsChanged;
use App\Events\IntentCardChanged;
use App\Models\User;

/**
 * Tells an open home that the counts moved and which cards to reload.
 */
class SurfaceUpdates
{
    public static function broadcast(User $user, int ...$intentIds): void
    {
        HomeCountsChanged::dispatch($user);

        foreach (array_values(array_unique($intentIds)) as $intentId) {
            if ($intentId > 0) {
                IntentCardChanged::dispatch($user, $intentId);
            }
        }
    }
}
