<?php

namespace App\Comms\Messages;

enum Direction: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';

    /**
     * The value stored on the message projection.
     */
    public function code(): string
    {
        return match ($this) {
            self::Incoming => 'in',
            self::Outgoing => 'out',
        };
    }
}
