<?php

namespace App\Comms\Surface;

use App\Comms\Gate\ActionClass;
use App\Comms\Gate\Gate;
use App\Comms\Gate\ProposalStatus;
use App\Comms\Intents\PersonRef;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsProposal;
use Carbon\CarbonImmutable;

/**
 * Voice guidance, a handoff, and hiding a card. Each one is an event the
 * pipeline applies, so a replay does the same thing.
 */
class IntentActions
{
    public function __construct(
        private Gate $gate,
        private PersonRef $people,
    ) {}

    public function guide(CommsEvent $event): void
    {
        $user = $event->user;
        $text = trim((string) ($event->normalized['text'] ?? ''));

        if ($user === null || $text === '') {
            return;
        }

        $intent = $this->intent($event);

        if ($intent === null) {
            return;
        }

        foreach ($this->proposals($intent, $event->normalized['proposal_ids'] ?? []) as $proposal) {
            $this->gate->edit($proposal, $text);
        }
    }

    public function snooze(CommsEvent $event): void
    {
        $until = $event->normalized['until'] ?? null;
        $intent = $this->intent($event);

        if ($intent === null || ! is_string($until) || $until === '') {
            return;
        }

        $intent->snoozed_until = CarbonImmutable::parse($until);
        $intent->save();
    }

    public function proposeTo(CommsIntent $intent, CommsPerson $person): void
    {
        $user = $intent->user;

        if ($user === null || $person->display_name === null) {
            return;
        }

        $this->gate->submit(
            user: $user,
            class: ActionClass::SendSlack,
            target: [
                'ref' => $this->people->key($person),
                'channel' => 'C1',
            ],
            payload: [
                'text' => '¿Puedes encargarte de «'.$intent->what.'»?',
                'to' => 'C1',
                'to_label' => $person->display_name,
                'delegate' => true,
            ],
            intent: $intent,
        );
    }

    private function intent(CommsEvent $event): ?CommsIntent
    {
        if ($event->user === null) {
            return null;
        }

        $key = (string) ($event->normalized['intent_key'] ?? '');

        if ($key === '') {
            return null;
        }

        return CommsIntent::query()->whereBelongsTo($event->user)->where('key', $key)->first();
    }

    /**
     * @return list<CommsProposal>
     */
    private function proposals(CommsIntent $intent, mixed $ids): array
    {
        $keys = [];

        foreach (is_array($ids) ? $ids : [] as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $keys[] = (int) $id;
            }
        }

        $query = CommsProposal::query()
            ->where('intent_id', $intent->id)
            ->whereIn('status', [ProposalStatus::Pending, ProposalStatus::Edited])
            ->orderBy('id');

        if ($keys !== []) {
            $query->whereIn('id', $keys);
        }

        return $query->get()->all();
    }
}
