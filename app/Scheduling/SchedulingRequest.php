<?php

namespace App\Scheduling;

/**
 * Something the person has to do, as it reaches the scheduler: from an alert
 * or asked for by voice, with what is known about how hard it is.
 */
final readonly class SchedulingRequest
{
    /**
     * @param  ?int  $effortMinutes  How long it should take, if said.
     * @param  ?string  $deadline  YYYY-MM-DD, if said.
     * @param  list<string>  $people  Others who should take part, by name or email.
     */
    public function __construct(
        public string $title,
        public ?string $context = null,
        public ?int $effortMinutes = null,
        public ?string $deadline = null,
        public array $people = [],
        public ?Urgency $urgency = null,
    ) {}
}
