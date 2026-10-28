<?php

namespace App\Alerts\Intents;

use App\Alerts\InboxCandidate;
use App\Analytics\ProductAnalytics;
use App\Analytics\SlackTriageEvent;
use App\Connectors\Slack\SlackAttention;
use App\Models\SlackAttentionProfile;
use App\Models\SlackTriageDecision;
use App\Models\User;

/**
 * Turns a model's Slack guess into the action the product uses, then keeps
 * a copy. PostHog receives only the whitelist.
 */
class SlackTriage
{
    public function __construct(private ProductAnalytics $analytics) {}

    /**
     * @param  ?array<string, mixed>  $signals
     */
    public function adjust(IntentDecision $decision, SlackThreadState $state, ?array $signals): IntentDecision
    {
        $action = $decision->resolvedAction();
        $owner = in_array($decision->owner, ['self', 'other', 'unknown'], true) ? $decision->owner : 'unknown';
        $target = filled($decision->target) ? $decision->target : null;
        $uncertain = $decision->uncertain;

        if ($state->alreadyAnswered && $action === SlackAction::Reply) {
            $action = $state->directed ? SlackAction::Notice : SlackAction::Ignore;
        } elseif (($state->place === 'dm' || $state->openedBySelf || $state->directed) && $uncertain && $action === SlackAction::Ignore) {
            $action = SlackAction::Reply;
            $owner = 'self';
        }

        if ($state->place === 'dm' || $state->openedBySelf) {
            $owner = 'self';
        }

        if (! $state->directed && $this->someoneElses($state->channel, $signals)) {
            $target = $target ?: $state->answeredBy;
            $owner = 'other';
            $action = filled($target) ? SlackAction::Redirect : SlackAction::Notice;
        }

        $hasTarget = $action === SlackAction::Redirect && filled($target);

        $adjusted = new IntentDecision(
            important: $action->shown(),
            reason: $decision->reason,
            relevance: $decision->relevance,
            intentId: $decision->intentId,
            goal: $decision->goal,
            action: $action,
            owner: $owner,
            hasTarget: $hasTarget,
            uncertain: $uncertain,
            target: $target,
            alreadyAnswered: $state->alreadyAnswered,
        );

        return $this->preference($adjusted, $state, $signals);
    }

    public function record(User $user, InboxCandidate $candidate, IntentDecision $decision, SlackThreadState $state, ?SlackAttentionProfile $profile): SlackTriageDecision
    {
        $row = $user->slackTriageDecisions()->firstOrNew([
            'external_id' => $candidate->externalId,
        ]);
        $fresh = ! $row->exists;

        $row->fill([
            'action' => $decision->resolvedAction()->value,
            'place' => $state->place,
            'owner' => $decision->owner,
            'reason' => $decision->reason,
            'uncertain' => $decision->uncertain,
            'has_target' => $decision->hasTarget,
            'target' => $decision->target,
            'already_answered' => $decision->alreadyAnswered,
            'policy_present' => $profile !== null && filled($profile->body),
            'thread_key' => $candidate->threadKey,
            'channel' => $state->channel,
            'candidate' => $candidate->toArray(),
        ]);
        $row->save();

        if ($fresh) {
            $this->analytics->capture($user, SlackTriageEvent::TriageDecided, [
                'action' => $row->action,
                'place' => $row->place,
                'owner' => $row->owner,
                'uncertain' => $row->uncertain,
                'already_answered' => $row->already_answered,
                'has_target' => $row->has_target,
                'policy_present' => $row->policy_present,
            ]);
        }

        return $row;
    }

    /**
     * A poke, a second mention or a later direct message on something that
     * was not raised as a reply is a miss: the earlier rows are marked and
     * this message becomes a reply.
     */
    public function recover(User $user, InboxCandidate $candidate, IntentDecision $decision, SlackThreadState $state): IntentDecision
    {
        $signal = $this->signal($candidate, $state);

        if ($candidate->threadKey === null || $signal === null) {
            return $decision;
        }

        $priors = $user->slackTriageDecisions()
            ->where('thread_key', $candidate->threadKey)
            ->where('external_id', '!=', $candidate->externalId)
            ->where('action', '!=', SlackAction::Reply->value)
            ->whereNull('missed_at')
            ->get();

        $latest = $priors->sortByDesc('id')->first();

        if ($latest === null) {
            return $decision;
        }

        foreach ($priors as $prior) {
            $prior->update(['missed_at' => now()]);
        }

        $this->analytics->capture($user, SlackTriageEvent::TriageMissed, [
            'signal' => $signal,
            'place' => $state->place,
            'action' => $latest->action,
        ]);

        if ($decision->resolvedAction() === SlackAction::Reply) {
            return $decision;
        }

        return new IntentDecision(
            important: true,
            reason: 'Te vuelven a preguntar por algo que había dejado pasar.',
            relevance: max($decision->relevance, 75),
            intentId: $decision->intentId,
            goal: $decision->goal,
            action: SlackAction::Reply,
            owner: 'self',
            uncertain: false,
        );
    }

    /**
     * A rule she already accepted beats the guess and the history.
     *
     * @param  ?array<string, mixed>  $signals
     */
    private function preference(IntentDecision $decision, SlackThreadState $state, ?array $signals): IntentDecision
    {
        foreach ($signals['rules'] ?? [] as $rule) {
            if (! is_array($rule) || ! $this->matches($rule, $state)) {
                continue;
            }

            $correction = (string) ($rule['correction'] ?? '');
            $target = is_string($rule['target'] ?? null) && $rule['target'] !== '' ? $rule['target'] : null;
            $action = match ($correction) {
                'always_notify' => SlackAction::Reply,
                'someone_else' => filled($target) ? SlackAction::Redirect : SlackAction::Notice,
                default => SlackAction::Ignore,
            };

            return new IntentDecision(
                important: $action->shown(),
                reason: $decision->reason,
                relevance: $decision->relevance,
                intentId: $decision->intentId,
                goal: $decision->goal,
                action: $action,
                owner: match ($correction) {
                    'always_notify' => 'self',
                    'someone_else' => 'other',
                    default => $decision->owner,
                },
                hasTarget: $action === SlackAction::Redirect && filled($target),
                uncertain: false,
                target: $target,
                alreadyAnswered: $state->alreadyAnswered,
            );
        }

        return $decision;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function matches(array $rule, SlackThreadState $state): bool
    {
        $channel = $rule['channel'] ?? null;
        $actor = $rule['actor'] ?? null;
        $words = is_array($rule['words'] ?? null) ? $rule['words'] : [];

        if ($channel === null && $actor === null && $words === []) {
            return false;
        }

        if (is_string($channel) && $channel !== $state->channel) {
            return false;
        }

        if (is_string($actor) && mb_strtolower($actor) !== mb_strtolower((string) $state->fromName)) {
            return false;
        }

        if ($words !== []) {
            $ask = mb_strtolower($state->ask);
            $hit = false;

            foreach ($words as $word) {
                if (is_string($word) && mb_strlen($word) >= 4 && str_contains($ask, mb_strtolower($word))) {
                    $hit = true;
                }
            }

            if (! $hit) {
                return false;
            }
        }

        return true;
    }

    private function signal(InboxCandidate $candidate, SlackThreadState $state): ?string
    {
        if (SlackAttention::pokesAgain($candidate->excerpt)) {
            return 're_ping';
        }

        if ($state->place === 'mention') {
            return 're_mention';
        }

        if ($state->place === 'dm') {
            return 're_ping';
        }

        return null;
    }

    /**
     * @param  ?array<string, mixed>  $signals
     */
    private function someoneElses(?string $channel, ?array $signals): bool
    {
        if ($channel === null || $signals === null) {
            return false;
        }

        $owned = $signals['owned_channels'] ?? [];

        return $owned !== [] && ! in_array($channel, $owned, true);
    }
}
