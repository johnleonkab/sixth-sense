<?php

namespace App\Jobs;

use App\Alerts\Intents\IntentAssigner;
use App\Alerts\Intents\IntentDecision;
use App\Alerts\Intents\SlackAction;
use App\Alerts\Intents\SlackThreadReader;
use App\Analytics\ProductAnalytics;
use App\Analytics\SlackTriageEvent;
use App\Models\SlackTriageDecision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * A question dropped in a channel she owns, still alone after a few hours,
 * becomes a reply. Someone else answering leaves it quiet.
 */
class PromoteUnansweredSlack implements ShouldQueue
{
    use Queueable;

    /** How long a channel question can sit alone before it is hers. */
    public const HOURS = 4;

    public int $timeout = 120;

    public function handle(SlackThreadReader $threads, IntentAssigner $assigner, ProductAnalytics $analytics): void
    {
        SlackTriageDecision::query()
            ->with(['user.slackAttentionProfile'])
            ->whereNull('settled_at')
            ->where('place', 'channel')
            ->whereIn('action', [SlackAction::Notice->value, SlackAction::Ignore->value])
            ->where('created_at', '<=', now()->subHours(self::HOURS))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->each(fn (SlackTriageDecision $decision) => $this->consider($decision, $threads, $assigner, $analytics));
    }

    private function consider(SlackTriageDecision $decision, SlackThreadReader $threads, IntentAssigner $assigner, ProductAnalytics $analytics): void
    {
        $user = $decision->user;
        $owned = is_array($user?->slackAttentionProfile?->signals) ? ($user->slackAttentionProfile->signals['owned_channels'] ?? []) : [];

        if ($user === null || ! in_array($decision->channel, $owned, true)) {
            $decision->update(['settled_at' => now()]);

            return;
        }

        $candidate = $decision->candidate();

        if (! str_contains($candidate->excerpt, '?')) {
            $decision->update(['settled_at' => now()]);

            return;
        }

        $state = $threads->read($user, $candidate);

        if ($state->alreadyAnswered) {
            $decision->update(['settled_at' => now(), 'already_answered' => true]);

            return;
        }

        $item = $assigner->raise($user, $candidate, new IntentDecision(
            important: true,
            reason: 'Nadie ha respondido en '.$decision->channel.', y ese canal lo sueles atender.',
            relevance: 70,
            intentId: null,
            goal: Str::limit($candidate->subject, 120),
            action: SlackAction::Reply,
            owner: 'self',
        ));

        $decision->update([
            'action' => SlackAction::Reply->value,
            'owner' => 'self',
            'uncertain' => false,
            'inbox_item_id' => $item?->id,
            'promoted_at' => now(),
            'settled_at' => now(),
        ]);

        $analytics->capture($user, SlackTriageEvent::TriageDecided, [
            'action' => SlackAction::Reply->value,
            'place' => 'channel',
            'owner' => 'self',
            'uncertain' => false,
            'already_answered' => false,
            'has_target' => false,
            'policy_present' => filled($user->slackAttentionProfile?->body),
        ]);
    }
}
