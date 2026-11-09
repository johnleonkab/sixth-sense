<?php

namespace App\Comms\Intents;

use App\Comms\People\Relation;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsPerson;

/**
 * Importance is 0–100 and comes only from code. An override lasts until its date.
 */
class ImportanceCalculator
{
    public function score(CommsIntent $intent): int
    {
        if ($intent->importance_override !== null && ($intent->importance_override_until === null || $intent->importance_override_until->isFuture())) {
            return min(100, (int) $intent->importance_override);
        }

        $intent->loadMissing(['participants.person', 'links.message']);
        $selfId = CommsPerson::query()->whereBelongsTo($intent->user)->where('relation', Relation::Self)->value('id');
        $requesters = $intent->participants->where('role', 'requester');
        $responders = $intent->participants->where('role', 'responder');
        $score = min(40, (int) $requesters->max(fn ($participant): int => (int) $participant->person->importance_weight));

        $userIsResponder = $selfId !== null && $responders->contains('person_id', $selfId);
        $someoneWaiting = $requesters->contains(fn ($participant): bool => $participant->person_id !== $selfId);

        if ($userIsResponder && $someoneWaiting && $intent->state === 'open') {
            $score += 10;
        }

        if ($intent->deadline !== null) {
            $due = $intent->deadline->copy()->startOfDay();
            $today = ($intent->last_activity_at ?? now())->copy()->startOfDay();

            if ($due->lt($today)) {
                $score += 25;
            } elseif ($due->lte($today->copy()->addDay())) {
                $score += 20;
            } elseif ($due->lte($today->copy()->addDays(3))) {
                $score += 10;
            }
        }

        $channels = $intent->links
            ->map(fn ($link) => $link->message?->channel_ref)
            ->filter()
            ->unique()
            ->count();

        if ($channels === 2) {
            $score += 10;
        } elseif ($channels >= 3) {
            $score += 20;
        }

        $flags = $intent->flags ?? [];
        $score += ($flags['production'] ?? false) ? 25 : 0;
        $score += ($flags['client'] ?? false) ? 15 : 0;
        $score += ($flags['money'] ?? false) ? 10 : 0;

        $userIsRequester = $selfId !== null && $requesters->contains('person_id', $selfId);
        $heavyResponder = $responders->contains(fn ($participant): bool => (int) $participant->person->importance_weight >= 25);

        if ($userIsRequester && $heavyResponder) {
            $score += 10;
        }

        return min(100, $score);
    }
}
