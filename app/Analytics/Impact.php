<?php

namespace App\Analytics;

use App\Actions\ActionStatus;
use App\Models\User;
use App\Scheduling\AgendaTaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * What sixth-sense saved the person, from what it already keeps: every
 * proposal they said "sí" to and that went out, every task it found time
 * for, every event it put in their calendar.
 */
class Impact
{
    /** Minutes saved by each proposal that went out (writing it, finding where to send it). */
    public const MINUTES_PER_PROPOSAL = 1;

    /** Minutes saved by each slot found in the calendar (looking through the week, everyone's). */
    public const MINUTES_PER_SLOT = 2;

    /** Services the proposals went to, by the kind of change. */
    private const SERVICES = [
        'chat' => 'Slack',
        'mail' => 'Email',
        'calendar' => 'Calendario',
        'jira' => 'Jira',
        'notion' => 'Notion',
        'docs' => 'Docs',
        'agenda' => 'Agenda',
    ];

    /**
     * Since a moment (or ever).
     *
     * @return array{minutes: int, proposals: int, slots: int, events: int, by_service: list<array{service: string, count: int}>}
     */
    public function summary(User $user, ?CarbonImmutable $since = null): array
    {
        $proposals = $this->sent($user, $since)->count();
        $slots = $this->slotsFound($user, $since)->count();

        return [
            'minutes' => $proposals * self::MINUTES_PER_PROPOSAL + $slots * self::MINUTES_PER_SLOT,
            'proposals' => $proposals,
            'slots' => $slots,
            'events' => $this->eventsCreated($user, $since),
            'by_service' => $this->sent($user, $since)
                ->pluck('type')
                ->countBy(fn (string $type): string => self::SERVICES[Str::before($type, '.')] ?? 'Otros')
                ->sortDesc()
                ->map(fn (int $count, string $service): array => ['service' => $service, 'count' => $count])
                ->values()
                ->all(),
        ];
    }

    /**
     * Minutes saved each week, oldest first, the current week last.
     *
     * @return list<array{week: string, minutes: int}>
     */
    public function weekly(User $user, int $weeks, string $timezone): array
    {
        $first = CarbonImmutable::now($timezone)->startOfWeek()->subWeeks($weeks - 1);
        $weekOf = fn (CarbonImmutable $at): string => $at->setTimezone($timezone)->startOfWeek()->toDateString();

        $minutes = collect()
            ->merge($this->sent($user, $first)->pluck('executed_at')->map(fn ($at): array => [$weekOf(CarbonImmutable::parse($at)), self::MINUTES_PER_PROPOSAL]))
            ->merge($this->slotsFound($user, $first)->pluck('created_at')->map(fn ($at): array => [$weekOf(CarbonImmutable::parse($at)), self::MINUTES_PER_SLOT]))
            ->groupBy(0)
            ->map(fn ($items): int => $items->sum(1));

        return collect(range(0, $weeks - 1))
            ->map(fn (int $index): string => $first->addWeeks($index)->toDateString())
            ->map(fn (string $week): array => ['week' => $week, 'minutes' => (int) ($minutes[$week] ?? 0)])
            ->all();
    }

    /**
     * Proposals the person said "sí" to, that went out.
     */
    private function sent(User $user, ?CarbonImmutable $since): Builder
    {
        return $user->pendingActions()->getQuery()
            ->where('status', ActionStatus::Executed)
            ->when($since, fn (Builder $actions) => $actions->where('executed_at', '>=', $since));
    }

    /**
     * Tasks the scheduler found time for (booked, or proposed when others take part).
     */
    private function slotsFound(User $user, ?CarbonImmutable $since): Builder
    {
        return $user->agendaTasks()->getQuery()
            ->whereIn('status', [AgendaTaskStatus::Scheduled, AgendaTaskStatus::Proposed])
            ->whereNotNull('starts_at')
            ->when($since, fn (Builder $tasks) => $tasks->where('created_at', '>=', $since));
    }

    /**
     * Events put in the calendar: meetings created on the person's "sí", and their own tasks booked.
     */
    private function eventsCreated(User $user, ?CarbonImmutable $since): int
    {
        $confirmed = $this->sent($user, $since)->where('type', 'calendar.change')->where('payload->kind', 'create')->count();

        $booked = $user->agendaTasks()->getQuery()
            ->where('status', AgendaTaskStatus::Scheduled)
            ->whereNotNull('event_ref')
            ->whereNull('pending_action_id')
            ->when($since, fn (Builder $tasks) => $tasks->where('created_at', '>=', $since))
            ->count();

        return $confirmed + $booked;
    }
}
