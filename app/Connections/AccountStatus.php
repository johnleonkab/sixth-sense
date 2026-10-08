<?php

namespace App\Connections;

enum AccountStatus: string
{
    case Active = 'active';

    /** The provider no longer accepts our tokens: the user has to authorise again. */
    case NeedsReconnection = 'needs_reconnection';
}
