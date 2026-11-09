<?php

namespace App\Comms\Playbooks;

use App\Comms\People\Relation;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\User;

/**
 * Event shape to a playbook key. A model is not asked when the shape is enough.
 */
class RouteRules
{
    /**
     * @return list<string>
     */
    public function keys(User $user, CommsIntent $intent, CommsMessage $message): array
    {
        $keys = [];
        $kind = $intent->flags['kind'] ?? null;

        if ($this->role($user, $intent, 'responder') && in_array($kind, ['question', 'review_comment', 'choice'], true)) {
            $keys[] = 'answer_from_sources';
        }

        if ($this->role($user, $intent, 'responder') && $kind === 'prepare') {
            $keys[] = 'prepare_work';
        }

        if ($this->lateReply($user, $intent, $message)) {
            $keys[] = 'rebuild_context';
        }

        return $keys;
    }

    private function lateReply(User $user, CommsIntent $intent, CommsMessage $message): bool
    {
        if (! $this->role($user, $intent, 'requester') || $message->direction !== 'in') {
            return false;
        }

        $previous = CommsMessage::query()
            ->whereIn('id', $intent->links()->pluck('message_id'))
            ->whereKeyNot($message->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        if ($previous === null || $previous->direction !== 'out') {
            return false;
        }

        return $message->occurred_at->greaterThan($previous->occurred_at->addHours(48));
    }

    private function role(User $user, CommsIntent $intent, string $role): bool
    {
        $self = CommsPerson::query()->whereBelongsTo($user)->where('relation', Relation::Self)->value('id');

        if ($self === null) {
            return false;
        }

        return $intent->participants()->where('role', $role)->where('person_id', $self)->exists();
    }
}
