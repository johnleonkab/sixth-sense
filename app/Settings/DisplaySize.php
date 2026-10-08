<?php

namespace App\Settings;

/**
 * How large canvases are shown on screen.
 */
enum DisplaySize: string
{
    case Compact = 'compact';
    case Normal = 'normal';
    case Large = 'large';

    public function label(): string
    {
        return match ($this) {
            self::Compact => 'Compacto',
            self::Normal => 'Normal',
            self::Large => 'Grande',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Compact => 'Cabe más en pantalla.',
            self::Normal => 'El tamaño de siempre.',
            self::Large => 'Todo más grande y fácil de leer de lejos.',
        };
    }

    /**
     * How much canvases are scaled.
     */
    public function scale(): float
    {
        return match ($this) {
            self::Compact => 0.9,
            self::Normal => 1.0,
            self::Large => 1.2,
        };
    }
}
