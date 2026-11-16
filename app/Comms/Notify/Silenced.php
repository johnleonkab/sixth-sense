<?php

namespace App\Comms\Notify;

/**
 * True while a rebuild is writing projections, so live alerts stay quiet.
 * The stale-batch question is asked after this is turned off.
 */
class Silenced
{
    public bool $active = false;
}
