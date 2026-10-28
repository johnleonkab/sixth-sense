<?php

namespace App\Alerts\Intents;

use App\Analytics\ProductAnalytics;
use App\Analytics\SlackTriageEvent;
use App\Models\SlackTriageDecision;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * What Slack left aside since yesterday. Opening it is the daily digest.
 */
class SlackDigest
{
    public function __construct(private ProductAnalytics $analytics) {}

    /**
     * @return array{title: string, count: int, items: list<array{number: int, ref: string, action: string, action_label: string, place_label: string, who: string, excerpt: string, reason: string, at: string}>}
     */
    public function open(User $user, string $timezone): array
    {
        $rows = $user->slackTriageDecisions()
            ->whereIn('action', [SlackAction::Notice->value, SlackAction::Ignore->value])
            ->whereNull('missed_at')
            ->where('created_at', '>=', now()->subDay()->startOfDay());

        $count = (clone $rows)->count();
        $decisions = (clone $rows)->latest('created_at')->limit(40)->get();

        $this->analytics->capture($user, SlackTriageEvent::DailyDigestRead, [
            'deprioritized_count' => $count,
        ]);

        return [
            'title' => match ($count) {
                0 => 'Nada dejado pasar desde ayer',
                1 => '1 dejado pasar desde ayer',
                default => "{$count} dejados pasar desde ayer",
            },
            'count' => $count,
            'items' => $decisions->values()->map(fn (SlackTriageDecision $decision, int $index): array => $this->item($decision, $index, $timezone))->all(),
        ];
    }

    /**
     * @return array{number: int, ref: string, action: string, action_label: string, place_label: string, who: string, excerpt: string, reason: string, at: string}
     */
    private function item(SlackTriageDecision $decision, int $index, string $timezone): array
    {
        $candidate = is_array($decision->candidate) ? $decision->candidate : [];
        $action = SlackAction::tryFrom($decision->action) ?? SlackAction::Ignore;

        return [
            'number' => $index + 1,
            'id' => $decision->id,
            'ref' => (string) ($candidate['external_id'] ?? $decision->external_id),
            'action' => $action->value,
            'action_label' => $action->label(),
            'place_label' => $this->place($decision->place),
            'who' => $this->who($candidate),
            'excerpt' => Str::limit((string) ($candidate['excerpt'] ?? ''), 180),
            'reason' => $decision->reason,
            'at' => $decision->created_at->timezone($timezone)->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function who(array $candidate): string
    {
        $from = $candidate['from'] ?? null;

        if (! is_array($from)) {
            return 'Alguien';
        }

        $name = $from['name'] ?? $from['email'] ?? null;

        return is_string($name) && $name !== '' ? $name : 'Alguien';
    }

    private function place(string $place): string
    {
        return match ($place) {
            'dm' => 'Mensaje directo',
            'group' => 'Grupo',
            'mention' => 'Mención',
            'thread' => 'Hilo',
            'channel' => 'Canal',
            default => 'Slack',
        };
    }
}
