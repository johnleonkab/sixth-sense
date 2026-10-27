<?php

namespace App\Alerts\Intents;

use App\Alerts\InboxCandidate;
use App\Models\User;

/**
 * Decides whether a new message is something the person has to answer, and
 * which intent it belongs to. The same ask can arrive by email and by Slack,
 * in more than one channel: that is one intent.
 */
interface IntentClassifier
{
    /**
     * @param  list<array{id: int, status: string, goal: string, who: string, source: string, threads: list<string>, excerpt: string}>  $intents
     */
    public function classify(User $user, InboxCandidate $candidate, array $intents, string $context): IntentDecision;
}
