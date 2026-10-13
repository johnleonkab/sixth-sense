<?php

namespace App\Scheduling;

enum AgendaTaskStatus: string
{
    /** The scheduler is deciding urgency and looking for a slot. */
    case Assessing = 'assessing';

    /** In the calendar (a task of the person's own). */
    case Scheduled = 'scheduled';

    /** A meeting with others, waiting for the person's "sí". */
    case Proposed = 'proposed';

    /** No slot before it is due: the person was alerted. */
    case Conflict = 'conflict';

    /** Taken off the calendar, or never wanted. */
    case Cancelled = 'cancelled';

    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Assessing => 'Buscando hueco',
            self::Scheduled => 'Agendada',
            self::Proposed => 'Propuesta',
            self::Conflict => 'Sin hueco',
            self::Cancelled => 'Cancelada',
            self::Failed => 'Ha fallado',
        };
    }
}
