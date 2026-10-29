<?php

namespace App\Alerts\Intents;

use App\Alerts\InboxCandidate;
use App\Alerts\Judges\RuleRelevanceJudge;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Groups a message with the conversation it already belongs to, and uses the
 * rule judge for whether it matters. Used when the model cannot answer.
 */
class RuleIntentClassifier implements IntentClassifier
{
    public function __construct(private RuleRelevanceJudge $rules) {}

    public function classify(User $user, InboxCandidate $candidate, array $intents, string $context): IntentDecision
    {
        $judgement = $this->rules->judge($user, $candidate);
        $intentId = null;

        foreach ($intents as $intent) {
            if ($candidate->threadKey !== null && in_array($candidate->threadKey, $intent['threads'], true)) {
                $intentId = $intent['id'];

                break;
            }
        }

        return new IntentDecision(
            important: $judgement->important,
            reason: $judgement->reason,
            relevance: $judgement->relevance,
            intentId: $intentId,
            goal: Str::limit(Str::squish($candidate->subject), 120),
        );
    }
}
