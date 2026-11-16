<?php

namespace App\Comms\People;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Intents\PersonRef;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIdentity;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Confirming "¿Son la misma persona?" appends the existing merge correction.
 * Replay applies that event again, so the two records stay one person.
 */
class PersonMerge
{
    public function __construct(private EventLog $log, private PersonRef $people) {}

    public function confirm(User $user, string $fromRef, string $intoRef, string $evidence): void
    {
        $keys = [$fromRef, $intoRef];
        sort($keys);

        $this->log->append(
            user: $user,
            type: EventType::UserCorrected,
            source: Source::User,
            externalId: 'person-merge:'.hash('sha256', $keys[0].'|'.$keys[1]),
            payload: null,
            normalized: [
                'action' => 'merge',
                'kind' => 'person',
                'from' => $fromRef,
                'into' => $intoRef,
                'evidence' => $evidence,
            ],
            causation: null,
            occurredAt: CarbonImmutable::now(),
        );
    }

    public function apply(CommsEvent $event): void
    {
        $data = $event->normalized ?? [];

        if ($event->user === null || ($data['kind'] ?? '') !== 'person' || ($data['action'] ?? '') !== 'merge') {
            return;
        }

        $from = $this->people->find($event->user, (string) ($data['from'] ?? ''));
        $into = $this->people->find($event->user, (string) ($data['into'] ?? ''));

        if (! $from instanceof CommsPerson || ! $into instanceof CommsPerson || $from->id === $into->id || $from->merged_into_id !== null) {
            return;
        }

        CommsIdentity::query()->where('person_id', $from->id)->update(['person_id' => $into->id]);
        CommsMessage::query()->where('author_person_id', $from->id)->update(['author_person_id' => $into->id]);
        $this->moveRecipients($event->user, $from, $into);
        $this->moveParticipants($from, $into);

        $from->merged_into_id = $into->id;
        $from->save();
    }

    private function moveRecipients(User $user, CommsPerson $from, CommsPerson $into): void
    {
        CommsMessage::query()->whereBelongsTo($user)->orderBy('id')->each(function (CommsMessage $message) use ($from, $into): void {
            $ids = array_map(intval(...), $message->recipient_person_ids ?? []);

            if (! in_array($from->id, $ids, true)) {
                return;
            }

            $message->recipient_person_ids = array_values(array_unique(array_map(
                fn (int $id): int => $id === $from->id ? $into->id : $id,
                $ids,
            )));
            $message->save();
        });
    }

    private function moveParticipants(CommsPerson $from, CommsPerson $into): void
    {
        CommsIntentParticipant::query()->where('person_id', $from->id)->orderBy('id')->each(function (CommsIntentParticipant $row) use ($into): void {
            $taken = CommsIntentParticipant::query()
                ->where('intent_id', $row->intent_id)
                ->where('person_id', $into->id)
                ->where('role', $row->role)
                ->exists();

            if ($taken) {
                $row->delete();

                return;
            }

            $row->person_id = $into->id;
            $row->save();
        });
    }
}
