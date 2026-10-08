<?php

namespace App\Actions\Handlers;

use App\Actions\ActionHandler;
use App\Connectors\Calendar\CalendarWriter;
use App\Connectors\Calendar\NewEvent;
use App\Connectors\Mail\MailAddress;
use App\Models\PendingAction;
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
            'respond' => $this->calendar->respond($user, $payload['ref'], $payload['response'], $payload['comment'] ?? null),
            'create' => $payload['url'] = $this->calendar->create($user, NewEvent::fromArray($payload['event'])),
            default => throw new InvalidArgumentException("Unknown calendar change [{$payload['kind']}]."),
        };

        return ['url' => $payload['url'] ?? null];
    }
}
