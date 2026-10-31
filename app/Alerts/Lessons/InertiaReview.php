<?php

namespace App\Alerts\Lessons;

use App\Models\ProposalOutcome;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Sending as is may be trust, or inertia. When almost everything goes out
 * untouched, a few of this week's sends are shown again to check by hand
 * that they were good, not just accepted. The same few all week.
 */
class InertiaReview
{
    /** From this share sent as is, with enough sends, it asks for a look. */
    public const THRESHOLD = 0.9;

    private const MIN_SENDS = 10;

    private const SAMPLE = 5;

    /**
     * @return array{rate: ?float, sends: int, sample: list<array{id: int, actor: ?string, title: ?string, text: string, at: string}>}
     */
    public function for(User $user): array
    {
        $outcomes = ProposalOutcome::query()
            ->whereBelongsTo($user)
            ->whereIn('outcome', [ProposalOutcome::SENT, ProposalOutcome::EDITED])
            ->where('created_at', '>=', now()->subDays(7))
            ->with(['inboxItem', 'pendingAction'])
            ->get();
        $sent = $outcomes->where('outcome', ProposalOutcome::SENT);
        $rate = $outcomes->isEmpty() ? null : round($sent->count() / $outcomes->count(), 2);

        if ($outcomes->count() < self::MIN_SENDS || $rate < self::THRESHOLD) {
            return ['rate' => $rate, 'sends' => $outcomes->count(), 'sample' => []];
        }

        $week = now()->format('o-W');

        return [
            'rate' => $rate,
            'sends' => $outcomes->count(),
            'sample' => $sent
                ->sortBy(fn (ProposalOutcome $outcome): int => crc32("{$outcome->id}:{$week}"))
                ->take(self::SAMPLE)
                ->map(fn (ProposalOutcome $outcome): array => [
                    'id' => $outcome->id,
                    'actor' => $outcome->inboxItem?->actor_name,
                    'title' => $outcome->inboxItem?->title,
                    'text' => Str::limit((string) ($outcome->pendingAction?->payload['message']['text'] ?? $outcome->pendingAction?->payload['email']['body'] ?? ''), 400),
                    'at' => $outcome->created_at->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }
}
