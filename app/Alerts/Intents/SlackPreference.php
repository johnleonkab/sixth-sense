<?php

namespace App\Alerts\Intents;

use App\Actions\Actions;
use App\Analytics\ProductAnalytics;
use App\Analytics\SlackTriageEvent;
use App\Comms\Context\Audience;
use App\Comms\Context\ContextWriter;
use App\Comms\Context\FactOrigin;
use App\Models\SlackPreferenceRule;
use App\Models\SlackTriageDecision;
use App\Models\User;

/**
 * Turns a tap, or a cold-start answer, into a readable rule. A tap waits for
 * "sí". A cold-start answer is already accepted.
 */
class SlackPreference
{
    public function __construct(private Actions $actions, private ProductAnalytics $analytics, private ContextWriter $context) {}

    public function propose(User $user, SlackTriageDecision $decision, string $correction): SlackPreferenceRule
    {
        $rule = $user->slackPreferenceRules()->create([
            'slack_triage_decision_id' => $decision->id,
            'correction' => $correction,
            'status' => 'proposed',
            'place' => $decision->place,
            'channel' => $decision->channel,
            'target' => $decision->target,
        ]);

        $pending = $this->actions->propose($user, 'slack.preference', 'Guardar la regla: '.$rule->sentence(), [
            'rule_id' => $rule->id,
        ]);
        $rule->update(['pending_action_id' => $pending->id]);

        $this->analytics->capture($user, SlackTriageEvent::TriageFeedback, [
            'action' => $decision->action,
            'correction' => $correction,
            'place' => $decision->place,
        ]);
        $this->analytics->capture($user, SlackTriageEvent::PreferenceRuleProposed, [
            'action' => $decision->action,
            'correction' => $correction,
        ]);

        return $rule->refresh();
    }

    /**
     * @param  list<string>  $words
     */
    public function acceptNow(User $user, string $correction, ?string $channel, ?string $actor, array $words): SlackPreferenceRule
    {
        $rule = $user->slackPreferenceRules()->create([
            'correction' => $correction,
            'status' => 'accepted',
            'channel' => $channel,
            'actor' => $actor,
            'words' => $words === [] ? null : $words,
            'accepted_at' => now(),
        ]);

        $this->remember($rule);
        $this->analytics->capture($user, SlackTriageEvent::PreferenceRuleAccepted, [
            'correction' => $correction,
        ]);

        return $rule;
    }

    public function accept(SlackPreferenceRule $rule): SlackPreferenceRule
    {
        if ($rule->status === 'accepted') {
            return $rule;
        }

        $rule->update(['status' => 'accepted', 'accepted_at' => now()]);
        $this->remember($rule);
        $this->analytics->capture($rule->user, SlackTriageEvent::PreferenceRuleAccepted, [
            'action' => $rule->decision?->action,
            'correction' => $rule->correction,
        ]);

        return $rule;
    }

    /**
     * @param  list<string>  $words
     */
    public function phrase(string $correction, ?string $channel, ?string $actor, array $words): string
    {
        if ($actor !== null) {
            return "Responde siempre a {$actor}.";
        }

        if ($words !== []) {
            return 'Este trimestre cuenta: '.implode(', ', $words).'.';
        }

        $where = $channel ?? 'los mensajes directos';
        $in = $channel !== null ? "En {$channel}" : 'En '.$where;

        return match ($correction) {
            'someone_else' => "{$in}, esto le toca a otra persona.",
            'always_notify' => "{$in}, avísame siempre.",
            default => "{$in}, mensajes de este tipo: no hace falta avisar.",
        };
    }

    /**
     * She said it: it sits above what was learned from how she answers.
     */
    private function remember(SlackPreferenceRule $rule): void
    {
        $this->context->record($rule->user, FactOrigin::Correction, [[
            'op' => 'add',
            'kind' => 'channel',
            'subject' => 'slack',
            'attribute' => 'rule',
            'value' => ['text' => $rule->sentence()],
            'audiences' => [Audience::Triage->value],
            'refs' => ["slack-rule:{$rule->id}"],
        ]], revision: "slack-rule:{$rule->id}");
    }
}
