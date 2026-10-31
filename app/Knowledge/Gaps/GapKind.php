<?php

namespace App\Knowledge\Gaps;

/**
 * What a question is about.
 */
enum GapKind: string
{
    case Meeting = 'meeting';
    case Term = 'term';

    /** How an alert the person marked as resolved was resolved. */
    case Resolution = 'resolution';

    public function label(): string
    {
        return match ($this) {
            self::Meeting => 'Reunión',
            self::Term => 'Término',
            self::Resolution => 'Resolución',
        };
    }
}
