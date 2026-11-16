<?php

namespace App\Comms\Windows;

use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsWindow;

/**
 * The messages a segmenter reads, in time order, and the people who wrote them.
 */
final readonly class AssembledWindow
{
    /**
     * @param  list<AssembledMessage>  $messages
     * @param  list<CommsPerson>  $people
     */
    public function __construct(
        public CommsWindow $window,
        public array $messages,
        public array $people,
    ) {}
}
