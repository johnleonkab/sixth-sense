<?php

namespace App\Actions\Handlers;

use App\Actions\ActionHandler;
use App\Connectors\Calendar\CalendarWriter;
use App\Connectors\Calendar\EventUpdate;
use App\Connectors\Calendar\NewEvent;
use App\Connectors\Mail\MailAddress;
use App\Models\PendingAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class ChangeCalendar implements ActionHandler
{
    public function __construct(private CalendarWriter $calendar) {}

    public function execute(PendingAction $action): array
    {
        $payload = $action->payload;
        $user = $action->user;
        $people = fn (): array => array_map(fn (array $person): MailAddress => new MailAddress($person['name'], $person['email']), $payload['people']);

        match ($payload['kind']) {
            'invite' => $this->calendar->invite($user, $payload['ref'], $people()),
            'summary' => $this->calendar->addSummary($user, $payload['ref'], $payload['summary'], $payload['heading']),
            'cancel' => $this->calendar->cancel($user, $payload['ref']),
            'update' => $this->calendar->update($user, $payload['ref'], EventUpdate::fromArray($payload['changes'])),
            'respond' => $this->calendar->respond($user, $payload['ref'], $payload['response'], $payload['comment'] ?? null),
            'create' => $payload['url'] = $this->createEvent($user, $payload),
            default => throw new InvalidArgumentException("Unknown calendar change [{$payload['kind']}]."),
        };

        return ['url' => $payload['url'] ?? null];
    }

    /**
     * A meeting may have taken its time from one of the person's own tasks.
     * That task is shortened first, then the meeting is created.
     *
     * @param  array<string, mixed>  $payload
     */
    private function createEvent(User $user, array $payload): string
    {
        foreach ($payload['squeeze'] ?? [] as $change) {
            $this->calendar->update($user, $change['ref'], new EventUpdate(
                start: CarbonImmutable::parse($change['start']),
                end: CarbonImmutable::parse($change['end']),
                notify: false,
            ));
        }

        return $this->calendar->create($user, NewEvent::fromArray($payload['event']))->url;
    }
}
