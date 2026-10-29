<?php

namespace App\Alerts\Intents;

use App\Alerts\InboxCandidate;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * For tests and demo mode. A message is the same intent when it continues a
 * conversation we already have, or when it repeats a distinctive word of that
 * intent's goal (the contract asked for by email and again on Slack).
 */
class FakeIntentClassifier implements IntentClassifier
{
    /** @var list<InboxCandidate> */
    public array $classified = [];

    public ?IntentDecision $next = null;

    public function classify(User $user, InboxCandidate $candidate, array $intents, string $context): IntentDecision
    {
        $this->classified[] = $candidate;

        if ($this->next !== null) {
            $decision = $this->next;
            $this->next = null;

            return $decision;
        }

        $important = (bool) preg_match('/urgente|antes del|necesito|firma|revis/iu', $candidate->subject.' '.$candidate->excerpt);
        $who = $candidate->from?->name ?? $candidate->from?->email ?? 'Alguien';

        return new IntentDecision(
            important: $important,
            reason: $important ? "{$who} te pide algo: {$candidate->subject}" : 'Informativo, sin nada que hacer.',
            relevance: $important ? 80 : 10,
            intentId: $this->match($candidate, $intents),
            goal: Str::limit(Str::squish($candidate->subject), 120),
        );
    }

    /**
     * @param  list<array{id: int, status: string, goal: string, who: string, source: string, threads: list<string>, excerpt: string}>  $intents
     */
    private function match(InboxCandidate $candidate, array $intents): ?int
    {
        $haystack = mb_strtolower($candidate->subject.' '.$candidate->excerpt);

        foreach ($intents as $intent) {
            if ($candidate->threadKey !== null && in_array($candidate->threadKey, $intent['threads'], true)) {
                return $intent['id'];
            }

            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($intent['goal'].' '.$intent['excerpt'])) ?: [] as $word) {
                if (mb_strlen($word) >= 6 && str_contains($haystack, $word)) {
                    return $intent['id'];
                }
            }
        }

        return null;
    }
}
