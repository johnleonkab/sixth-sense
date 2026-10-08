<?php

namespace App\Alerts;

/**
 * Where an alert stands. Alerts about messages wait for the person to answer;
 * reminders (meetings) only inform.
 */
enum AlertStatus: string
{
    /** Waiting for the person to answer. */
    case Pending = 'pending';

    /** The person answered, here or in the service itself. */
    case Resolved = 'resolved';

    /** The person decided it needs nothing. */
    case Dismissed = 'dismissed';

    /** Nothing to answer: a reminder. */
    case Notice = 'notice';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Resolved => 'Resuelta',
            self::Dismissed => 'Descartada',
            self::Notice => 'Aviso',
        };
    }
}
