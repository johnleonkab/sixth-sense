<?php

namespace App\Knowledge\Gaps;

/**
 * What a question is about.
 */
enum GapKind: string
{
    case Meeting = 'meeting';
    case Term = 'term';

    public function label(): string
    {
        return match ($this) {
            self::Meeting => 'Reunión',
            self::Term => 'Término',
        };
    }
}
