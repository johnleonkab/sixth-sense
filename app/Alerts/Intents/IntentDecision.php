<?php

namespace App\Alerts\Intents;

/**
 * Where a new message belongs: an intent already open, or a new one.
 */
final readonly class IntentDecision
{
    /**
     * @param  ?int  $intentId  An existing alert, when this message is the same ask. Null opens a new intent.
     * @param  string  $goal  The ask in a few words, the same words if it is one we already have.
     */
    public function __construct(
        public bool $important,
        public string $reason,
        public int $relevance,
        public ?int $intentId,
        public string $goal,
    ) {}
}
