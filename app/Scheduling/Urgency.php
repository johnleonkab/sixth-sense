<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;

/**
 * How soon a task has to be done, as the scheduler judges it.
 */
enum Urgency: string
{
    case Now = 'now';
    case Today = 'today';
    case ThisWeek = 'this_week';
    case NextWeek = 'next_week';
    case Whenever = 'whenever';

    public function label(): string
    {
        return match ($this) {
            self::Now => 'Urgente',
            self::Today => 'Hoy',
            self::ThisWeek => 'Esta semana',
            self::NextWeek => 'La semana que viene',
            self::Whenever => 'Sin prisa',
        };
    }

    /**
     * The last moment a slot may end, from now (in the person's timezone).
     */
    public function latestEnd(CarbonImmutable $now): CarbonImmutable
    {
        return match ($this) {
            self::Now => $now->addWeekday()->endOfDay(),
            self::Today => $now->endOfDay(),
            self::ThisWeek => $now->endOfWeek(),
            self::NextWeek => $now->addWeek()->endOfWeek(),
            self::Whenever => $now->addWeeks(3)->endOfWeek(),
        };
    }

    /**
     * The first moment worth looking from: next week's work starts next week.
     */
    public function earliestStart(CarbonImmutable $now): CarbonImmutable
    {
        return $this === self::NextWeek ? $now->addWeek()->startOfWeek() : $now;
    }
}
