<?php

namespace App\Voice\OpenUi;

use Carbon\CarbonImmutable;

/**
 * Dates the way lists show them, the same as the canvases did.
 */
class Dates
{
    /**
     * "10:12" today, "ayer", "lun" this week, "7 oct" before.
     */
    public static function relative(CarbonImmutable $date, string $timezone, ?CarbonImmutable $now = null): string
    {
        $local = $date->setTimezone($timezone);
        $today = ($now ?? CarbonImmutable::now())->setTimezone($timezone)->startOfDay();
        $daysAgo = (int) $local->startOfDay()->diffInDays($today, false);

        return match (true) {
            $daysAgo <= 0 => $local->format('H:i'),
            $daysAgo === 1 => 'ayer',
            $daysAgo < 7 => rtrim($local->locale('es')->isoFormat('ddd'), '.'),
            default => str_replace('.', '', $local->locale('es')->isoFormat('D MMM')),
        };
    }
}
