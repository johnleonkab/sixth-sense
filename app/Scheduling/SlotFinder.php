<?php

namespace App\Scheduling;

use App\Connectors\Calendar\CalendarEvent;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\EventQuery;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Finds the first free slot of a given length between two moments: within
 * working hours, outside lunch, clear of the person's events (with a short
 * margin) and, for meetings, of the other people's busy times.
 */
class SlotFinder
{
    /** All-day events that do take the day: holidays, time off. */
    private const DAY_OFF = '/vacaciones|festivo|ooo|out of office|holiday|baja|libre|day off|ausencia/iu';

    public function __construct(private CalendarReader $calendar) {}

    /**
     * @param  list<string>  $others  Emails of other people who must be free too.
     * @return ?array{CarbonImmutable, CarbonImmutable}
     */
    public function find(User $user, CarbonImmutable $from, CarbonImmutable $to, int $minutes, array $others = []): ?array
    {
        $timezone = $from->getTimezone()->getName();
        $busy = $this->busy($user, $from, $to, $others);
        $step = (int) config('agenda.granularity_minutes');

        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            if (! in_array($day->dayOfWeekIso, config('agenda.working_days'), true) || $this->dayOff($busy['days_off'], $day)) {
                continue;
            }

            $opens = $day->setTimeFromTimeString(config('agenda.day_starts'));
            $closes = $day->setTimeFromTimeString(config('agenda.day_ends'))->min($to);
            $start = $this->roundUp($opens->max($from), $step);

            for (; $start->addMinutes($minutes)->lessThanOrEqualTo($closes); $start = $start->addMinutes($step)) {
                $end = $start->addMinutes($minutes);

                if (! $this->overlapsLunch($start, $end) && ! $this->clashes($busy['periods'], $start, $end)) {
                    return [$start->setTimezone($timezone), $end->setTimezone($timezone)];
                }
            }
        }

        return null;
    }

    /**
     * How busy each of the coming days is, one line each, for the assessor.
     *
     * @return list<string>
     */
    public function load(User $user, CarbonImmutable $from, int $days = 7): array
    {
        $reading = $this->calendar->read($user, new EventQuery($from->startOfDay(), $from->addDays($days)->endOfDay()));
        $lines = [];

        for ($day = $from->startOfDay(); count($lines) < $days; $day = $day->addDay()) {
            if (! in_array($day->dayOfWeekIso, config('agenda.working_days'), true)) {
                continue;
            }

            $events = array_filter($reading->events, fn (CalendarEvent $event): bool => ! $event->allDay && ! $event->declined && $event->start->isSameDay($day));
            $minutes = array_sum(array_map(fn (CalendarEvent $event): int => (int) $event->start->diffInMinutes($event->end), $events));

            $lines[] = $day->locale('es')->isoFormat('ddd D MMM').': '.count($events).' reuniones, '.round($minutes / 60, 1).' h ocupadas';
        }

        return $lines;
    }

    /**
     * @param  list<string>  $others
     * @return array{periods: list<array{CarbonImmutable, CarbonImmutable}>, days_off: list<CarbonImmutable>}
     */
    private function busy(User $user, CarbonImmutable $from, CarbonImmutable $to, array $others): array
    {
        $buffer = (int) config('agenda.buffer_minutes');
        $reading = $this->calendar->read($user, new EventQuery($from->startOfDay(), $to->endOfDay()));
        $periods = [];
        $daysOff = [];

        foreach ($reading->events as $event) {
            if ($event->declined) {
                continue;
            }

            if ($event->allDay) {
                if (preg_match(self::DAY_OFF, $event->title)) {
                    for ($day = $event->start->startOfDay(); $day->lessThan($event->end); $day = $day->addDay()) {
                        $daysOff[] = $day;
                    }
                }

                continue;
            }

            $periods[] = [$event->start->subMinutes($buffer), $event->end->addMinutes($buffer)];
        }

        foreach ($this->calendar->busy($user, $others, $from, $to) as $times) {
            array_push($periods, ...$times);
        }

        return ['periods' => $periods, 'days_off' => $daysOff];
    }

    /**
     * @param  list<array{CarbonImmutable, CarbonImmutable}>  $periods
     */
    private function clashes(array $periods, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($periods as [$from, $to]) {
            if ($from->lessThan($end) && $to->greaterThan($start)) {
                return true;
            }
        }

        return false;
    }

    private function overlapsLunch(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $lunch = config('agenda.lunch');

        if (blank($lunch['starts'] ?? null)) {
            return false;
        }

        $from = $start->setTimeFromTimeString($lunch['starts']);
        $to = $start->setTimeFromTimeString($lunch['ends']);

        return $from->lessThan($end) && $to->greaterThan($start);
    }

    /**
     * @param  list<CarbonImmutable>  $daysOff
     */
    private function dayOff(array $daysOff, CarbonImmutable $day): bool
    {
        foreach ($daysOff as $off) {
            if ($off->isSameDay($day)) {
                return true;
            }
        }

        return false;
    }

    private function roundUp(CarbonImmutable $moment, int $step): CarbonImmutable
    {
        $minutes = $moment->hour * 60 + $moment->minute + ($moment->second > 0 ? 1 : 0);
        $rounded = (int) ceil($minutes / $step) * $step;

        return $moment->startOfDay()->addMinutes($rounded);
    }
}
