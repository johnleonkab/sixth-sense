<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;

/**
 * What the scheduler decided about a task: how soon, how long, and whether
 * it is the person's own work or a meeting with others.
 */
final readonly class TaskAssessment
{
    /**
     * @param  'task'|'meeting'  $kind
     * @param  list<string>  $people  Others who take part (meetings), by name or email.
     */
    public function __construct(
        public string $title,
        public Urgency $urgency,
        public string $reason,
        public int $durationMinutes,
        public string $kind = 'task',
        public ?CarbonImmutable $deadline = null,
        public array $people = [],
        public ?string $description = null,
    ) {}
}
