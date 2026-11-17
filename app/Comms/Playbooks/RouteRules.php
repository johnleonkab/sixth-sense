<?php

namespace App\Comms\Playbooks;

use App\Comms\People\Relation;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsPlaybookRun;
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
        if ($intent->possible_ask) {
            return [];
        }

        if ($this->shape($message->text) !== null || $this->clarifying($user, $intent)) {
            return ['clarify_then_answer'];
        }

        $keys = [];
        $kind = $intent->flags['kind'] ?? null;
        $responder = $this->role($user, $intent, 'responder');
        $work = $intent->deadline !== null || (is_array($intent->steps) && $intent->steps !== []);

        if ($responder && in_array($kind, ['question', 'review_comment', 'choice'], true)) {
            $keys[] = 'answer_from_sources';
        }

        if ($responder && ($kind === 'prepare' || ($intent->kind === 'request' && $work))) {
            $keys[] = 'prepare_work';
        }

        if ($this->lateReply($user, $intent, $message)) {
            $keys[] = 'rebuild_context';
        }

        return $keys;
    }

    /**
     * Cases 2, 34 and 47. Null when the message is an ordinary ask.
     */
    public function shape(string $text): ?string
    {
        $haystack = mb_strtolower($text);

        if (preg_match('/\b(cuándo estará|cuando estará|when will|estimaci[oó]n|para cuándo|para cuando)\b/u', $haystack) === 1) {
            return 'estimate';
        }

        if (preg_match('/\b(funcionalidad|feature)\b/u', $haystack) === 1) {
            return 'feature';
        }

        if (preg_match('/\b(call|llamada|reuni[oó]n)\b/u', $haystack) === 1
            && preg_match('/\b(epic|algo|something)\b/u', $haystack) === 1
            && preg_match('/\b[A-Z]{2,}-\d+\b/', $text) !== 1) {
            return 'call';
        }

        return null;
    }

    private function clarifying(User $user, CommsIntent $intent): bool
    {
        return CommsPlaybookRun::query()
            ->whereBelongsTo($user)
            ->where('intent_id', $intent->id)
            ->where('playbook', 'clarify_then_answer')
            ->whereIn('step', ['subject', 'doubts'])
            ->exists();
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
