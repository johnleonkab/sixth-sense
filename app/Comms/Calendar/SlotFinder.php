<?php

namespace App\Comms\Calendar;

use App\Comms\Watchers\BusinessCalendar;
use App\Models\Comms\CommsCalendarEvent;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Free time inside working hours, skipping focus blocks and busy events.
 * Urgent work gets the earliest slot. Otherwise up to three.
 */
class SlotFinder
{
    public function __construct(private BusinessCalendar $calendar) {}

    /**
     * @return list<array{starts_at: string, ends_at: string}>
     */
    public function find(User $user, CarbonImmutable $from, CarbonImmutable $deadline, int $minutes, bool $urgent): array
    {
        $minutes = in_array($minutes, [15, 30, 45, 60, 90], true) ? $minutes : 30;
        $timezone = $this->calendar->timezone($user);
        $start = $from->timezone($timezone);
        $end = $deadline->timezone($timezone);
        $busy = CommsCalendarEvent::query()
            ->whereBelongsTo($user)
            ->where('status', 'confirmed')
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->get();
        $found = [];
        $cursor = $start;

        while ($cursor->lt($end)) {
            $slotEnd = $cursor->addMinutes($minutes);

            if ($this->fits($user, $cursor, $slotEnd, $end, $busy)) {
                $found[] = [
                    'starts_at' => $cursor->utc()->toIso8601String(),
                    'ends_at' => $slotEnd->utc()->toIso8601String(),
                ];
            }

            $cursor = $cursor->addMinutes(15);
        }

        if ($found === []) {
            return [];
        }

        if ($urgent) {
            return [$found[0]];
        }

        $earliestDay = CarbonImmutable::parse($found[0]['starts_at'])->timezone($timezone)->toDateString();
        $nextDay = null;

        foreach ($found as $slot) {
            $day = CarbonImmutable::parse($slot['starts_at'])->timezone($timezone)->toDateString();

            if ($day !== $earliestDay) {
                $nextDay = $slot;

                break;
            }
        }

        $picked = [$found[0]];

        if ($nextDay !== null) {
            $picked[] = $nextDay;
        }

        $last = $found[array_key_last($found)];

        if ($last['starts_at'] !== $picked[0]['starts_at'] && ($nextDay === null || $last['starts_at'] !== $nextDay['starts_at'])) {
            $picked[] = $last;
        }

        return array_slice($picked, 0, 3);
    }

    /**
     * @param  iterable<int, CommsCalendarEvent>  $busy
     */
    private function fits(User $user, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $deadline, iterable $busy): bool
    {
        if ($end->greaterThan($deadline) || ! $this->calendar->isWorking($user, $start) || ! $this->calendar->isWorking($user, $end->subMinute())) {
            return false;
        }

        foreach ($busy as $event) {
            if ($event->starts_at->lt($end) && $event->ends_at->gt($start)) {
                return false;
            }
        }

        return true;
    }
}
