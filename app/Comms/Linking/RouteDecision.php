<?php

namespace App\Comms\Linking;

final readonly class RouteDecision
{
    /**
     * @param  bool  $apply  The operation goes to the state machine.
     * @param  bool  $uncertainOperation  Applied as uncertain: a possible ask (for `open`), or a change the user confirms.
     * @param  bool  $uncertainLink  The message's link carries the uncertain marker.
     * @param  bool  $playbooksAllowed  A playbook may run on the result.
     * @param  bool  $recordSignal  Recorded as a signal on the topic or intent.
     */
    public function __construct(
        public bool $apply,
        public bool $uncertainOperation,
        public bool $uncertainLink,
        public bool $playbooksAllowed,
        public bool $recordSignal,
    ) {}
}
