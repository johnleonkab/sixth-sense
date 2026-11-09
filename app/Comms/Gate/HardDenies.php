<?php

namespace App\Comms\Gate;

use App\Comms\Events\EventType;
use App\Comms\Intents\PersonRef;
use App\Comms\People\Relation;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsProposal;
use App\Models\User;

/**
 * Reasons that refuse a write no matter what the policy table says.
 */
class HardDenies
{
    public function __construct(private PersonRef $people) {}

    public function reason(User $user, CommsProposal $proposal): ?string
    {
        if ($this->sensitive($proposal)) {
            return 'sensitive';
        }

        if ($this->client($user, $proposal)) {
            return 'client';
        }

        if ($proposal->states_product_facts && ($proposal->verification['passed'] ?? false) !== true) {
            return 'verification';
        }

        if ($proposal->commits_date && ($proposal->payload['date_from_user'] ?? false) !== true) {
            return 'date';
        }

        if (! $this->knownTarget($user, $proposal)) {
            return 'target';
        }

        $allows = $proposal->allows;

        if (is_array($allows) && ! in_array($proposal->action_class->value, $allows, true)) {
            return 'allows';
        }

        return null;
    }

    private function sensitive(CommsProposal $proposal): bool
    {
        if (($proposal->payload['sensitive'] ?? false) === true) {
            return true;
        }

        $intent = $proposal->intent;

        if ($intent === null) {
            return false;
        }

        if (($intent->flags['sensitive'] ?? false) === true) {
            return true;
        }

        return $intent->links()->whereHas('message', function ($query): void {
            $query->where('flags->sensitive', true);
        })->exists();
    }

    private function client(User $user, CommsProposal $proposal): bool
    {
        if ($proposal->user_initiated || $this->answered($user, $proposal)) {
            return false;
        }

        $person = $this->people->find($user, (string) ($proposal->target['ref'] ?? ''));

        return $person?->relation === Relation::Client;
    }

    private function answered(User $user, CommsProposal $proposal): bool
    {
        $ids = $proposal->source_event_ids;

        if ($ids === []) {
            return false;
        }

        return CommsEvent::query()
            ->whereBelongsTo($user)
            ->whereIn('id', $ids)
            ->where('type', EventType::UserAnswered)
            ->exists();
    }

    private function knownTarget(User $user, CommsProposal $proposal): bool
    {
        if (($proposal->target['from_user'] ?? false) === true) {
            return true;
        }

        $ref = (string) ($proposal->target['ref'] ?? '');

        if ($ref === '') {
            return false;
        }

        if ($this->people->find($user, $ref) !== null) {
            return true;
        }

        $intent = $proposal->intent;

        if ($intent === null) {
            return false;
        }

        $intent->loadMissing('participants.person.identities');

        foreach ($intent->participants as $participant) {
            if ($this->people->key($participant->person) === $ref) {
                return true;
            }
        }

        return false;
    }
}
