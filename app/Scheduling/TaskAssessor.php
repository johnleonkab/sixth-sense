<?php

namespace App\Scheduling;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Decides how urgent a task is, how long it takes and whether it needs other
 * people, from what the person is working on lately. Any agent fits here.
 */
interface TaskAssessor
{
    /**
     * @param  CarbonImmutable  $now  Now, in the person's timezone.
     * @param  ?string  $context  What is known of the person's work and how long things take, as a data block.
     * @param  list<string>  $calendarLoad  How busy the coming days are, one line each.
     *
     * @throws \Throwable when it could not decide; the scheduler then uses safe defaults.
     */
    public function assess(User $user, SchedulingRequest $request, CarbonImmutable $now, ?string $context, array $calendarLoad): TaskAssessment;
}
