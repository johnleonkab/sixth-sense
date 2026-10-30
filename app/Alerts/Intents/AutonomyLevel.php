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

    /**
     * What this level means for the person, in one sentence.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::Watch => 'Solo te aviso y te explico por qué; tú decides qué hacer.',
            self::Draft => 'Te dejo el borrador listo; lo envías tú con un toque o con un «sí».',
            self::Act => 'Lo envío yo, con unos segundos para deshacer.',
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
