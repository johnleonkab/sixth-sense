<?php

namespace App\Alerts\Intents;

/**
 * Where a new message belongs: an intent already open, or a new one.
 * Slack also names the action. Gmail and Jira leave it empty and keep
 * important.
 */
final readonly class IntentDecision
{
    /**
     * @param  ?int  $intentId  An existing alert, when this message is the same ask. Null opens a new intent.
     * @param  string  $goal  The ask in a few words, the same words if it is one we already have.
     * @param  ?string  $target  Who should answer, when the action is to redirect. Kept here, never sent out.
     */
    public function __construct(
        public bool $important,
        public string $reason,
        public int $relevance,
        public ?int $intentId,
        public string $goal,
        public ?SlackAction $action = null,
        public string $owner = 'unknown',
        public bool $hasTarget = false,
        public bool $uncertain = false,
        public ?string $target = null,
        public bool $alreadyAnswered = false,
    ) {}

    /**
     * Slack uses the action. Everywhere else, important still means "reply".
     */
    public function resolvedAction(): SlackAction
    {
        return $this->action ?? ($this->important ? SlackAction::Reply : SlackAction::Ignore);
    }

    /**
     * Whether this becomes an alert. Notice and ignore stay in the decision log.
     */
    public function shown(): bool
    {
        if ($this->action === null) {
            return $this->important;
        }

        return $this->action->shown();
    }
}
