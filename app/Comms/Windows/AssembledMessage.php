<?php

namespace App\Comms\Windows;

use App\Models\Comms\CommsMessage;

/**
 * One line of a window: the message, and whether it is new in this window
 * or earlier context.
 */
final readonly class AssembledMessage
{
    public function __construct(
        public CommsMessage $message,
        public bool $new,
    ) {}
}
