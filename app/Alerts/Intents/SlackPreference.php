<?php

namespace App\Alerts\Intents;

use App\Actions\Actions;
use App\Analytics\ProductAnalytics;
use App\Analytics\SlackTriageEvent;
use App\Models\SlackPreferenceRule;
use App\Models\SlackTriageDecision;
use App\Models\User;

/**
 * Turns a tap, or a cold-start answer, into a readable rule. A tap waits for
 * "sí". A cold-start answer is already accepted.
 */
class SlackPreference
{
    public function __construct(private Actions $actions, private ProductAnalytics $analytics) {}

    public function propose(User $user, SlackTriageDecision $decision, string $correction): SlackPreferenceRule
    {
        $rule = $user->slackPreferenceRules()->create([
            'slack_triage_decision_id' => $decision->id,
            'correction' => $correction,
            'status' => 'proposed',
            'place' => $decision->place,
            'channel' => $decision->channel,
            'target' => $decision->target,
            'body' => $this->phrase($correction, $decision->channel, null, []),
        ]);

        $pending = $this->actions->propose($user, 'slack.preference', 'Guardar la regla: '.$rule->body, [
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
    public function acceptNow(User $user, string $correction, string $body, ?string $channel, ?string $actor, array $words): SlackPreferenceRule
    {
        $rule = $user->slackPreferenceRules()->create([
            'correction' => $correction,
            'status' => 'accepted',
            'channel' => $channel,
            'actor' => $actor,
            'words' => $words === [] ? null : $words,
            'body' => $body,
            'accepted_at' => now(),
        ]);

        $this->rewrite($user);
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
        $this->rewrite($rule->user);
        $this->analytics->capture($rule->user, SlackTriageEvent::PreferenceRuleAccepted, [
            'action' => $rule->decision?->action,
            'correction' => $rule->correction,
        ]);

        return $rule;
    }

    public function rewrite(User $user): void
    {
        $accepted = $user->slackPreferenceRules()->where('status', 'accepted')->latest('id')->get();
        $profile = $user->slackAttentionProfile()->firstOrCreate([], [
            'body' => '',
            'signals' => [],
            'built_at' => now(),
        ]);
        $signals = $profile->signals ?? [];
        $signals['rules'] = $accepted->map(fn (SlackPreferenceRule $rule): array => [
            'correction' => $rule->correction,
            'channel' => $rule->channel,
            'place' => $rule->place,
            'actor' => $rule->actor,
            'target' => $rule->target,
            'words' => $rule->words ?? [],
        ])->all();

        $preface = $accepted->isEmpty()
            ? ''
            : "Reglas que ha dicho ella, por encima del histórico:\n".$accepted->pluck('body')->implode("\n")."\n\n";

        $profile->update([
            'signals' => $signals,
            'body' => $preface.$this->history((string) $profile->body),
        ]);
        $user->unsetRelation('slackAttentionProfile');
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

    private function history(string $body): string
    {
        $header = "Reglas que ha dicho ella, por encima del histórico:\n";

        if (! str_starts_with($body, $header)) {
            return $body;
        }

        $rest = substr($body, strlen($header));
        $parts = preg_split("/\n\n/", $rest, 2);

        return $parts[1] ?? '';
    }
}
