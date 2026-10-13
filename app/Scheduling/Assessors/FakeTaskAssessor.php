<?php

namespace App\Scheduling\Assessors;

use App\Models\User;
use App\Scheduling\SchedulingRequest;
use App\Scheduling\TaskAssessment;
use App\Scheduling\TaskAssessor;
use App\Scheduling\Urgency;
use Carbon\CarbonImmutable;

/**
 * For tests and demo mode: urgent when it says so, half an hour, a meeting
 * when someone else takes part.
 */
class FakeTaskAssessor implements TaskAssessor
{
    /** @var list<array{request: SchedulingRequest, work_context: ?string, load: list<string>}> */
    public array $assessed = [];

    public function assess(User $user, SchedulingRequest $request, CarbonImmutable $now, ?string $workContext, array $calendarLoad): TaskAssessment
    {
        $this->assessed[] = ['request' => $request, 'work_context' => $workContext, 'load' => $calendarLoad];
        $urgent = (bool) preg_match('/urgente|hoy|ya/iu', $request->title.' '.$request->context);

        return new TaskAssessment(
            title: $request->title,
            urgency: $request->urgency ?? ($urgent ? Urgency::Today : Urgency::ThisWeek),
            reason: $urgent ? 'Lo piden para hoy.' : 'No corre prisa, pero conviene esta semana.',
            durationMinutes: $request->effortMinutes ?? 30,
            kind: $request->people === [] ? 'task' : 'meeting',
            deadline: $request->deadline ? CarbonImmutable::parse($request->deadline, $now->getTimezone()) : null,
            people: $request->people,
        );
    }
}
