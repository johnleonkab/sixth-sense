<?php

namespace App\Scheduling;

use App\Connectors\Calendar\CalendarEvent;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\EventQuery;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Finds the first free slot of a given length between two moments: within
 * working hours, outside lunch, clear of meetings. A short task may sit in a
 * tight gap, the day may stretch a little, and one of the person's own tasks
 * may be shortened when nothing else fits.
 */
class SlotFinder
{
    /** All-day events that do take the day: holidays, time off. */
    private const DAY_OFF = '/vacaciones|festivo|ooo|out of office|holiday|baja|libre|day off|ausencia/iu';

    public function __construct(private CalendarReader $calendar) {}

    /**
     * @param  list<string>  $others  Emails of other people who must be free too.
     * @return ?array{0: CarbonImmutable, 1: CarbonImmutable, 2?: list<array{ref: string, start: string, end: string}>}
     */
    public function find(User $user, CarbonImmutable $from, CarbonImmutable $to, int $minutes, array $others = []): ?array
    {
        $minutes = max(5, $minutes);
        $map = $this->map($user, $from, $to, $others);

        $slot = $this->scan($from, $to, $minutes, $this->blocked($map, tight: false), stretch: false);

        if ($slot !== null) {
            return $slot;
        }

        $slot = $this->scan($from, $to, $minutes, $this->blocked($map, tight: true), stretch: false);

        if ($slot !== null) {
            return $slot;
        }

        $slot = $this->scan($from, $to, $minutes, $this->blocked($map, tight: true), stretch: true);

        if ($slot !== null) {
            return $slot;
        }

        return $this->squeeze($from, $to, $minutes, $map);
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
     * Meetings, other people's busy time, and the person's own tasks.
     *
     * @param  list<string>  $others
     * @return array{hard: list<array{CarbonImmutable, CarbonImmutable}>, raw: list<array{CarbonImmutable, CarbonImmutable}>, flexible: list<array{CarbonImmutable, CarbonImmutable, string}>, days_off: list<CarbonImmutable>}
     */
    private function map(User $user, CarbonImmutable $from, CarbonImmutable $to, array $others): array
    {
        $buffer = (int) config('agenda.buffer_minutes');
        $reading = $this->calendar->read($user, new EventQuery($from->startOfDay(), $to->endOfDay()));
        $hard = [];
        $raw = [];
        $flexible = [];
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

            if ($event->flexible && $event->ref !== null) {
                $flexible[] = [$event->start, $event->end, $event->ref];

                continue;
            }

            $raw[] = [$event->start, $event->end];
            $hard[] = [$event->start->subMinutes($buffer), $event->end->addMinutes($buffer)];
        }

        foreach ($this->calendar->busy($user, $others, $from, $to) as $times) {
            array_push($hard, ...$times);
            array_push($raw, ...$times);
        }

        return ['hard' => $hard, 'raw' => $raw, 'flexible' => $flexible, 'days_off' => $daysOff];
    }

    /**
     * Busy periods for a pass. A tight pass drops the margin, so a 15 minute
     * task can sit in a 30 minute gap.
     *
     * @param  array{hard: list<array{CarbonImmutable, CarbonImmutable}>, raw: list<array{CarbonImmutable, CarbonImmutable}>, flexible: list<array{CarbonImmutable, CarbonImmutable, string}>, days_off: list<CarbonImmutable>}  $map
     * @return array{periods: list<array{CarbonImmutable, CarbonImmutable}>, days_off: list<CarbonImmutable>}
     */
    private function blocked(array $map, bool $tight): array
    {
        $buffer = $tight ? 0 : (int) config('agenda.buffer_minutes');
        $periods = $tight ? $map['raw'] : $map['hard'];

        foreach ($map['flexible'] as [$start, $end]) {
            $periods[] = [$start->subMinutes($buffer), $end->addMinutes($buffer)];
        }

        return ['periods' => $periods, 'days_off' => $map['days_off']];
    }

    /**
     * @param  array{periods: list<array{CarbonImmutable, CarbonImmutable}>, days_off: list<CarbonImmutable>}  $busy
     * @return ?array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function scan(CarbonImmutable $from, CarbonImmutable $to, int $minutes, array $busy, bool $stretch): ?array
    {
        $timezone = $from->getTimezone()->getName();
        $step = (int) config('agenda.granularity_minutes');
        $stretchBy = $stretch ? (int) config('agenda.stretch_minutes') : 0;

        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            if (! in_array($day->dayOfWeekIso, config('agenda.working_days'), true) || $this->dayOff($busy['days_off'], $day)) {
                continue;
            }

            $opens = $day->setTimeFromTimeString(config('agenda.day_starts'));
            $closes = $day->setTimeFromTimeString(config('agenda.day_ends'));

            if ($stretch) {
                $opens = $opens->subMinutes($stretchBy)->max($day->setTimeFromTimeString(config('agenda.stretch_earliest')));
                $closes = $closes->addMinutes($stretchBy)->min($day->setTimeFromTimeString(config('agenda.stretch_latest')));
            }

            $closes = $closes->min($to);
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
     * No free gap even after stretching the day: shorten one of the person's
     * own tasks so this one fits beside it.
     *
     * @param  array{hard: list<array{CarbonImmutable, CarbonImmutable}>, raw: list<array{CarbonImmutable, CarbonImmutable}>, flexible: list<array{CarbonImmutable, CarbonImmutable, string}>, days_off: list<CarbonImmutable>}  $map
     * @return ?array{0: CarbonImmutable, 1: CarbonImmutable, 2: list<array{ref: string, start: string, end: string}>}
     */
    private function squeeze(CarbonImmutable $from, CarbonImmutable $to, int $minutes, array $map): ?array
    {
        if ($map['flexible'] === []) {
            return null;
        }

        $minimum = (int) config('agenda.task_minimum_minutes');
        $timezone = $from->getTimezone()->getName();
        $step = (int) config('agenda.granularity_minutes');
        $stretchBy = (int) config('agenda.stretch_minutes');

        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            if (! in_array($day->dayOfWeekIso, config('agenda.working_days'), true) || $this->dayOff($map['days_off'], $day)) {
                continue;
            }

            $opens = $day->setTimeFromTimeString(config('agenda.day_starts'))->subMinutes($stretchBy)->max($day->setTimeFromTimeString(config('agenda.stretch_earliest')));
            $closes = $day->setTimeFromTimeString(config('agenda.day_ends'))->addMinutes($stretchBy)->min($day->setTimeFromTimeString(config('agenda.stretch_latest')))->min($to);
            $start = $this->roundUp($opens->max($from), $step);

            for (; $start->addMinutes($minutes)->lessThanOrEqualTo($closes); $start = $start->addMinutes($step)) {
                $end = $start->addMinutes($minutes);

                if ($this->overlapsLunch($start, $end) || $this->clashes($map['raw'], $start, $end)) {
                    continue;
                }

                $adjustments = $this->shorten($map['flexible'], $start, $end, $minimum);

                if ($adjustments === null) {
                    continue;
                }

                return [$start->setTimezone($timezone), $end->setTimezone($timezone), $adjustments];
            }
        }

        return null;
    }

    /**
     * How to shorten each task the slot overlaps, keeping at least the minimum.
     * Null when one of them cannot give way.
     *
     * @param  list<array{CarbonImmutable, CarbonImmutable, string}>  $flexible
     * @return ?list<array{ref: string, start: string, end: string}>
     */
    private function shorten(array $flexible, CarbonImmutable $slotStart, CarbonImmutable $slotEnd, int $minimum): ?array
    {
        $adjustments = [];

        foreach ($flexible as [$start, $end, $ref]) {
            if ($slotEnd->lessThanOrEqualTo($start) || $slotStart->greaterThanOrEqualTo($end)) {
                continue;
            }

            if ($slotStart->lessThanOrEqualTo($start) && $slotEnd->greaterThanOrEqualTo($end)) {
                return null;
            }

            $keepStart = $slotStart->greaterThan($start) ? (int) $start->diffInMinutes($slotStart) : 0;
            $keepEnd = $slotEnd->lessThan($end) ? (int) $slotEnd->diffInMinutes($end) : 0;

            if ($keepStart >= $minimum && $keepStart >= $keepEnd) {
                $adjustments[] = ['ref' => $ref, 'start' => $start->toIso8601String(), 'end' => $slotStart->toIso8601String()];

                continue;
            }

            if ($keepEnd >= $minimum) {
                $adjustments[] = ['ref' => $ref, 'start' => $slotEnd->toIso8601String(), 'end' => $end->toIso8601String()];

                continue;
            }

            return null;
        }

        return $adjustments === [] ? null : $adjustments;
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
