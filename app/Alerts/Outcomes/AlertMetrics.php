<?php

namespace App\Alerts\Outcomes;

use App\Models\ProposalOutcome;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Whether the pillars work, from what happened to each proposal (stage 1):
 * how often a proposal is accepted, how much it is edited, why alerts are
 * discarded, how long until something is done, how many expire, and what
 * slipped through. By week and by class of action.
 */
class AlertMetrics
{
    /** What an alert ended in; adjustments (people, lengths) are not an end. */
    private const ENDS = [ProposalOutcome::SENT, ProposalOutcome::EDITED, ProposalOutcome::UNDONE, ProposalOutcome::DISCARDED, ProposalOutcome::RESOLVED, ProposalOutcome::EXPIRED];

    /**
     * @return array{weeks: list<array<string, mixed>>, classes: list<array<string, mixed>>}
     */
    public function for(User $user, int $weeks = 6, ?string $timezone = null): array
    {
        $timezone ??= (string) config('agenda.timezone');
        $since = CarbonImmutable::now($timezone)->startOfWeek()->subWeeks($weeks - 1);
        $outcomes = ProposalOutcome::query()
            ->whereBelongsTo($user)
            ->whereIn('outcome', self::ENDS)
            ->where('created_at', '>=', $since)
            ->get(['id', 'class', 'outcome', 'reason', 'edit_size', 'seconds_to_outcome', 'created_at']);
        $missed = $user->slackTriageDecisions()->whereNotNull('missed_at')->where('missed_at', '>=', $since)->pluck('missed_at');
        $weekOf = fn (mixed $at): string => CarbonImmutable::parse($at)->setTimezone($timezone)->startOfWeek()->toDateString();

        $rows = [];

        for ($week = $since; $week->lessThanOrEqualTo(CarbonImmutable::now($timezone)); $week = $week->addWeek()) {
            $key = $week->toDateString();
            $rows[] = [
                'week' => $key,
                ...$this->measure($outcomes->filter(fn (ProposalOutcome $outcome): bool => $weekOf($outcome->created_at) === $key)),
                'missed' => $missed->filter(fn (mixed $at): bool => $weekOf($at) === $key)->count(),
            ];
        }

        return [
            'weeks' => $rows,
            'classes' => $outcomes->groupBy(fn (ProposalOutcome $outcome): string => $outcome->class ?? 'reply')
                ->map(fn (Collection $group, string $class): array => ['class' => $class, ...$this->measure($group)])
                ->sortByDesc('total')
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, ProposalOutcome>  $outcomes
     * @return array{total: int, acceptance: ?float, edit_rate: ?float, edit_size: ?int, discards: array<string, ?float>, median_minutes: ?int, expired: ?float}
     */
    private function measure(Collection $outcomes): array
    {
        $total = $outcomes->count();
        $sent = $outcomes->whereIn('outcome', [ProposalOutcome::SENT, ProposalOutcome::EDITED]);
        $edited = $outcomes->where('outcome', ProposalOutcome::EDITED);
        $discarded = $outcomes->where('outcome', ProposalOutcome::DISCARDED);
        $acted = $outcomes->whereIn('outcome', [ProposalOutcome::SENT, ProposalOutcome::EDITED, ProposalOutcome::DISCARDED, ProposalOutcome::RESOLVED])->pluck('seconds_to_outcome')->filter(fn (?int $seconds): bool => $seconds !== null);

        return [
            'total' => $total,
            'acceptance' => self::share($sent->count(), $total),
            'edit_rate' => self::share($edited->count(), $sent->count()),
            'edit_size' => $edited->isEmpty() ? null : (int) round($edited->avg('edit_size')),
            'discards' => collect([...ProposalOutcome::REASONS, null])
                ->mapWithKeys(fn (?string $reason): array => [$reason ?? 'none' => self::share($discarded->where('reason', $reason)->count(), $total)])
                ->all(),
            'median_minutes' => $acted->isEmpty() ? null : (int) round($acted->median() / 60),
            'expired' => self::share($outcomes->where('outcome', ProposalOutcome::EXPIRED)->count(), $total),
        ];
    }

    private static function share(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round($part / $whole, 2);
    }
}
