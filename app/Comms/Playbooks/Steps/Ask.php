<?php

namespace App\Comms\Playbooks\Steps;

use App\Comms\Notify\Notifier;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsQuestion;
use App\Models\User;

/**
 * One question for the points the sources did not settle.
 */
class Ask
{
    public function __construct(private Notifier $notifier) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  list<int>  $eventIds
     */
    public function open(User $user, CommsIntent $intent, string $text, array $context, array $eventIds, string $urgency = 'now'): CommsQuestion
    {
        $question = CommsQuestion::query()->updateOrCreate(
            ['user_id' => $user->id, 'key' => 'q:'.$intent->key],
            [
                'intent_id' => $intent->id,
                'text' => $text,
                'context' => $context,
                'options' => [],
            ],
        );

        $this->notifier->store($user, 'question', $urgency, 'question', $question->id, 'n:q:'.$intent->key, $eventIds);

        return $question;
    }
}
