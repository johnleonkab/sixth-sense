<?php

namespace App\Comms\Watchers;

use App\Comms\Intents\TriageRules;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsWatcher;
use App\Models\User;

/**
 * Cheap checks for a watcher. A model is not called: an exact allow-list
 * match is enough, and anything still open stays unmet until the clock.
 */
class ConditionEvaluator
{
    public function __construct(private TriageRules $rules) {}

    public function met(User $user, CommsWatcher $watcher): bool
    {
        $intent = $watcher->intent;

        if ($intent === null || $intent->state === 'settled') {
            return $intent !== null;
        }

        $intent->loadMissing('links.message.author.identities');

        foreach ($intent->links as $link) {
            $message = $link->message;

            if ($message instanceof CommsMessage && $this->rules->inboxLabel($message) !== null) {
                return true;
            }
        }

        return false;
    }
}
