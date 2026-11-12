<?php

namespace App\Comms\Gate\WriteTools;

use App\Connectors\Calendar\CalendarWriter;
use App\Connectors\Calendar\NewEvent;
use App\Models\Comms\CommsProposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Creates one calendar event through the calendar writer. A second call
 * returns the id already recorded. Delete cancels it when still possible.
 */
class CreateCalendarEvent
{
    public function __construct(private CalendarWriter $calendar) {}

    public function send(User $user, CommsProposal $proposal): string
    {
        if (filled($proposal->external_id)) {
            return (string) $proposal->external_id;
        }

        $payload = $proposal->payload;
        $accounts = $this->calendar->accounts($user);
        $accountId = (int) ($payload['account_id'] ?? 0);

        if ($accountId === 0) {
            $accountId = $accounts[0]->accountId ?? 0;
        }

        if ($accounts === [] && $accountId === 0) {
            throw new RuntimeException('No hay una cuenta de Google Calendar con permiso para crear eventos. Actívalo en Configuración y vuelve a conectarla.');
        }

        $created = $this->calendar->create($user, new NewEvent(
            accountId: $accountId,
            title: (string) ($payload['title'] ?? $payload['text'] ?? 'Bloque'),
            start: CarbonImmutable::parse((string) ($payload['starts_at'] ?? '')),
            end: CarbonImmutable::parse((string) ($payload['ends_at'] ?? '')),
            description: filled($payload['text'] ?? null) ? (string) $payload['text'] : null,
            videoCall: false,
            task: true,
        ));

        return $created->ref;
    }

    public function delete(CommsProposal $proposal): void
    {
        $payload = $proposal->payload;

        if (filled($proposal->external_id) && $proposal->user !== null && ! ($payload['deleted'] ?? false)) {
            $this->calendar->cancel($proposal->user, (string) $proposal->external_id);
        }

        $payload['deleted'] = true;
        $proposal->payload = $payload;
        $proposal->save();
    }
}
