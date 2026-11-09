<?php

namespace App\Comms\Watchers;

use App\Comms\People\Relation;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsWatcher;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Case 5: the user asked for something specific and is still waiting.
 * An existing deadline is left where it is. An acknowledgement does not move it.
 */
class IntentWatcher
{
    public function __construct(
        private BusinessCalendar $calendar,
        private ConditionEvaluator $evaluator,
    ) {}

    public function sync(User $user, CommsIntent $intent): void
    {
        $intent->loadMissing('participants');

        if ($intent->state === 'settled') {
            CommsWatcher::query()
                ->whereBelongsTo($user)
                ->where('intent_id', $intent->id)
                ->where('state', 'active')
                ->update(['state' => 'met']);

            return;
        }

        if ($intent->state !== 'open' || ! $this->qualifies($user, $intent)) {
            return;
        }

        $key = $intent->key.':delivery';
        $existing = CommsWatcher::query()->whereBelongsTo($user)->where('key', $key)->first();

        if ($existing !== null) {
            return;
        }

        $from = CarbonImmutable::parse($intent->last_activity_at ?? $intent->created_at ?? now());
        $deadline = filled($intent->deadline)
            ? $this->calendar->endOfWorkOn($user, CarbonImmutable::instance($intent->deadline))
            : $this->calendar->addDays($user, $from, 2);

        CommsWatcher::query()->create([
            'user_id' => $user->id,
            'intent_id' => $intent->id,
            'key' => $key,
            'condition_type' => 'delivery',
            'condition' => [
                'reply' => true,
                'delivery' => true,
                'expects' => $intent->expects,
            ],
            'deadline_at' => $deadline,
            'attempt' => 0,
            'state' => 'active',
        ]);
    }

    public function check(User $user, CommsIntent $intent): void
    {
        $watchers = CommsWatcher::query()
            ->whereBelongsTo($user)
            ->where('intent_id', $intent->id)
            ->where('state', 'active')
            ->get();

        foreach ($watchers as $watcher) {
            if ($this->evaluator->met($user, $watcher)) {
                $watcher->state = 'met';
                $watcher->save();
            }
        }
    }

    private function qualifies(User $user, CommsIntent $intent): bool
    {
        $selfId = CommsPerson::query()->whereBelongsTo($user)->where('relation', Relation::Self)->value('id');

        if ($selfId === null || ! filled($intent->expects)) {
            return false;
        }

        $userIsRequester = $intent->participants->contains(
            fn ($participant): bool => $participant->role === 'requester' && $participant->person_id === $selfId,
        );

        return $userIsRequester && $intent->importance >= (int) config('comms.notify.followup_importance');
    }
}
