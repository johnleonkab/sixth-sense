<?php

namespace App\Comms\Jobs;

/**
 * True while the consumer is applying one event, so events it appends are
 * not dispatched back into the same queue.
 */
class Consuming
{
    public bool $active = false;
}
