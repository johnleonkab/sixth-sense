<?php

namespace App\Comms\Watchers;

use App\Models\Comms\CommsPreference;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Working days from the user's preference. Monday is 1.
 */
class BusinessCalendar
{
    public function timezone(User $user): string
    {
        return CommsPreference::query()->whereBelongsTo($user)->value('timezone')
            ?? (string) config('app.timezone', 'UTC');
    }

    /**
     * @return list<int>
     */
    public function workDays(User $user): array
    {
        $days = CommsPreference::query()->whereBelongsTo($user)->value('work_days');

        if (! is_array($days) || $days === []) {
            return [1, 2, 3, 4, 5];
        }

        return array_values(array_map(intval(...), $days));
    }

    public function addDays(User $user, CarbonImmutable $from, int $days): CarbonImmutable
    {
        $cursor = $from->timezone($this->timezone($user));
        $workDays = $this->workDays($user);
        $added = 0;

        while ($added < $days) {
            $cursor = $cursor->addDay();

            if (in_array($cursor->dayOfWeekIso, $workDays, true)) {
                $added++;
            }
        }

        return $this->atWorkEnd($user, $cursor);
    }

    public function endOfWorkOn(User $user, CarbonImmutable $day): CarbonImmutable
    {
        return $this->atWorkEnd($user, $day->timezone($this->timezone($user)));
    }

    public function isWorking(User $user, CarbonImmutable $moment): bool
    {
        $local = $moment->timezone($this->timezone($user));

        if (! in_array($local->dayOfWeekIso, $this->workDays($user), true)) {
            return false;
        }

        $preference = CommsPreference::query()->whereBelongsTo($user)->first();
        $start = $preference->work_start ?? '09:00';
        $end = $preference->work_end ?? '19:00';
        $clock = $local->format('H:i');

        return $clock >= $start && $clock < $end;
    }

    private function atWorkEnd(User $user, CarbonImmutable $moment): CarbonImmutable
    {
        $end = CommsPreference::query()->whereBelongsTo($user)->value('work_end') ?? '19:00';
        [$hour, $minute] = array_map(intval(...), explode(':', (string) $end));

        return $moment->timezone($this->timezone($user))->setTime($hour, $minute, 0)->utc();
    }
}
