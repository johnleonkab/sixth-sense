<?php

namespace App\Comms\Surface;

/**
 * The home groups by who has the ball (D10), with their Spanish labels.
 */
enum BallGroup: string
{
    case NeedsYou = 'needs_you';
    case SystemWorking = 'system_working';
    case WaitingOnOthers = 'waiting_on_others';
    case DoneToday = 'done_today';

    /** Listed under the line "N posibles peticiones", not in the four groups. */
    case PossibleAsk = 'possible_ask';

    /** Asked about together, in one batch. */
    case Stale = 'stale';

    /** Snoozed, expired, or settled before today. */
    case Hidden = 'hidden';

    public function label(): string
    {
        return match ($this) {
            self::NeedsYou => 'Te toca',
            self::SystemWorking => 'En marcha',
            self::WaitingOnOthers => 'Esperando a otros',
            self::DoneToday => 'Hecho hoy',
            self::PossibleAsk => 'Posibles peticiones',
            self::Stale => 'Sin movimiento',
            self::Hidden => '',
        };
    }
}
