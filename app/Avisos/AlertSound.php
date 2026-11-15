<?php

namespace App\Avisos;

/**
 * A synthesized alert sound, the same set as docs/prototype.html.
 */
enum AlertSound: string
{
    case Drop = 'gota';
    case Bell = 'campana';
    case Knock = 'toque';
    case Wood = 'madera';
    case Chord = 'acorde';
    case Pulse = 'pulso';
    case Silent = 'silencio';

    public function label(): string
    {
        return match ($this) {
            self::Drop => 'Gota',
            self::Bell => 'Campana',
            self::Knock => 'Doble toque',
            self::Wood => 'Madera',
            self::Chord => 'Acorde',
            self::Pulse => 'Pulso grave',
            self::Silent => 'Sin sonido',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $sound): string => $sound->value, self::cases());
    }
}
