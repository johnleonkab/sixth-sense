<?php

namespace App\Connectors\Calendar;

use App\Models\User;

/**
 * For tests and demo mode: records the changes instead of making them.
 */
class FakeCalendarWriter implements CalendarWriter
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $changes = [];

    public function accounts(User $user): array
    {
        return [new CalendarAccount(0, 'demo@sixth-sense.test')];
    }

    public function canChange(User $user, string $ref): bool
    {
        return str_starts_with($ref, 'demo:');
    }

    public function invite(User $user, string $ref, array $people): void
    {
        $this->changes[] = ['invite', ['ref' => $ref, 'people' => array_map(fn ($person) => $person->email, $people)]];
    }

    public function addSummary(User $user, string $ref, string $summary, string $heading): void
    {
        $this->changes[] = ['summary', ['ref' => $ref, 'summary' => $summary, 'heading' => $heading]];
    }

    public function create(User $user, NewEvent $event): ?string
    {
        $this->changes[] = ['create', $event->toArray()];

        return null;
    }

    public function cancel(User $user, string $ref): void
    {
        $this->changes[] = ['cancel', ['ref' => $ref]];
    }

    public function respond(User $user, string $ref, string $response, ?string $comment = null): void
    {
        $this->changes[] = ['respond', ['ref' => $ref, 'response' => $response, 'comment' => $comment]];
    }
}
