<?php

namespace App\Comms\Events;

use App\Models\Comms\CommsContextFact;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIdentity;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsOwnedArea;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsPreference;
use App\Models\Comms\CommsQuestion;
use App\Models\Comms\CommsSignal;
use App\Models\Comms\CommsWatcher;
use App\Models\User;

/**
 * The tables replay fills. Empty groups are left out so a phase with no rows
 * still matches a fixture that expects nothing.
 */
class Projections
{
    public function clear(User $user): void
    {
        CommsContextFact::query()->whereBelongsTo($user)->delete();
        CommsNotification::query()->whereBelongsTo($user)->delete();
        CommsQuestion::query()->whereBelongsTo($user)->delete();
        CommsWatcher::query()->whereBelongsTo($user)->delete();
        CommsSignal::query()->whereBelongsTo($user)->delete();
        CommsConversationLink::query()->whereBelongsTo($user)->delete();
        CommsIntentParticipant::query()->whereBelongsTo($user)->delete();
        CommsIntent::query()->whereBelongsTo($user)->delete();
        CommsOwnedArea::query()->whereBelongsTo($user)->delete();
        CommsPreference::query()->whereBelongsTo($user)->delete();
        CommsMessage::query()->whereBelongsTo($user)->delete();
        CommsIdentity::query()->whereBelongsTo($user)->delete();
        CommsPerson::query()->whereBelongsTo($user)->delete();
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function capture(User $user): array
    {
        $captured = [
            'messages' => CommsMessage::query()->whereBelongsTo($user)->orderBy('source')->orderBy('external_id')->get()
                ->map(fn (CommsMessage $message): array => [
                    'source' => $message->source->value,
                    'external_id' => $message->external_id,
                    'text' => $message->text,
                    'direction' => $message->direction,
                    'deleted' => $message->deleted_at !== null,
                ])->all(),
            'people' => CommsPerson::query()->whereBelongsTo($user)->with('identities')->orderBy('display_name')->get()
                ->map(fn (CommsPerson $person): array => [
                    'display_name' => $person->display_name,
                    'relation' => $person->relation->value,
                    'identities' => $person->identities
                        ->sortBy(fn (CommsIdentity $identity): string => $identity->source->value.':'.$identity->external_id)
                        ->values()
                        ->map(fn (CommsIdentity $identity): array => [
                            'source' => $identity->source->value,
                            'external_id' => $identity->external_id,
                            'email' => $identity->email,
                        ])->all(),
                ])->all(),
        ];

        return array_filter($captured);
    }

    public function apply(CommsEvent $event): void {}
}
