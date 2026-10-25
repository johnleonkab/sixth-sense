<?php

namespace App\Alerts\Push;

use App\Models\PushSubscription;

/**
 * Delivers one payload to a person's browsers.
 */
interface PushSender
{
    /**
     * @param  iterable<int, PushSubscription>  $subscriptions
     */
    public function send(iterable $subscriptions, string $payload): bool;
}
