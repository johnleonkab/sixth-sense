<?php

namespace App\Alerts\Intents;

/**
 * How far Slack may go for one action. Watch explains, draft proposes,
 * act sends and keeps the undo window.
 */
enum AutonomyLevel: string
{
    case Watch = 'watch';
    case Draft = 'draft';
    case Act = 'act';

    public function label(): string
    {
        return match ($this) {
            self::Watch => 'Ver',
            self::Draft => 'Proponer',
            self::Act => 'Hacer',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Watch => 0,
            self::Draft => 1,
            self::Act => 2,
        };
    }
}
