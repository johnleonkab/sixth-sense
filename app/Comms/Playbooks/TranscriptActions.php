<?php

namespace App\Comms\Playbooks;

use App\Comms\Models\ModelAnswerKind;
use App\Comms\Models\ModelRouter;
use App\Comms\People\Relation;
use App\Comms\Playbooks\Steps\Ask;
use App\Comms\Watchers\BusinessCalendar;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsWatcher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;

/**
 * A transcript becomes commitments. The user's own open intents. Other
 * people's commitments to the user become watchers. A later message that
 * disagrees is a finding, stored as a question.
 */
class TranscriptActions
{
    public function __construct(
        private Ask $ask,
        private BusinessCalendar $calendar,
        private Container $container,
    ) {}

    public function apply(CommsEvent $event): void
    {
        $user = $event->user;
        $data = $event->normalized ?? [];

        if ($user === null || ($data['kind'] ?? '') !== 'transcript') {
            return;
        }

        $answer = $this->container->make(ModelRouter::class)->ask(
            user: $user,
            task: 'extract_actions',
            input: (string) ($data['text'] ?? ''),
            schema: [
                'type' => 'object',
                'required' => ['actions'],
                'properties' => [
                    'actions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['owner', 'what', 'quote'],
                            'properties' => [
                                'owner' => ['type' => 'string'],
                                'what' => ['type' => 'string'],
                                'due' => ['type' => 'string', 'nullable' => true],
                                'quote' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            eventIds: [$event->id],
        );

        if ($answer->kind !== ModelAnswerKind::Accepted || ! is_array($answer->output)) {
            return;
        }

        $self = CommsPerson::query()->whereBelongsTo($user)->where('relation', Relation::Self)->first();

        if ($self === null) {
            return;
        }

        $quotes = [];

        foreach (array_values($answer->output['actions'] ?? []) as $index => $action) {
            if (! is_array($action) || ! is_string($action['what'] ?? null)) {
                continue;
            }

            $quotes[] = (string) ($action['quote'] ?? $action['what']);
            $owner = mb_strtolower((string) ($action['owner'] ?? ''));
            $mine = $owner === 'self' || $owner === mb_strtolower($self->display_name);
            $intent = CommsIntent::query()->create([
                'user_id' => $user->id,
                'key' => 'evt:'.$event->id.':'.$index,
                'what' => mb_substr((string) $action['what'], 0, 280),
                'state' => 'open',
                'deadline' => filled($action['due'] ?? null) ? $action['due'] : null,
                'expects' => (string) $action['what'],
                'importance' => 40,
                'flags' => ['kind' => 'commitment'],
                'opened_event_id' => $event->id,
            ]);
            CommsIntentParticipant::query()->create([
                'user_id' => $user->id,
                'intent_id' => $intent->id,
                'person_id' => $self->id,
                'role' => $mine ? 'responder' : 'requester',
            ]);

            if ($mine) {
                continue;
            }

            $other = $this->person($user, (string) $action['owner']);
            CommsIntentParticipant::query()->create([
                'user_id' => $user->id,
                'intent_id' => $intent->id,
                'person_id' => $other->id,
                'role' => 'responder',
            ]);
            $due = filled($action['due'] ?? null)
                ? CarbonImmutable::parse((string) $action['due'])
                : $this->calendar->addDays($user, CarbonImmutable::parse($event->occurred_at), 2);
            CommsWatcher::query()->create([
                'user_id' => $user->id,
                'intent_id' => $intent->id,
                'key' => $intent->key.':commitment',
                'condition_type' => 'delivery',
                'condition' => ['expects' => $action['what']],
                'deadline_at' => $due,
                'attempt' => 0,
                'on_fire' => 'transcript',
                'state' => 'active',
            ]);
        }

        $this->findings($user, $event, $quotes);
    }

    /**
     * @param  list<string>  $quotes
     */
    private function findings(User $user, CommsEvent $event, array $quotes): void
    {
        if ($quotes === []) {
            return;
        }

        $recent = CommsMessage::query()
            ->whereBelongsTo($user)
            ->where('occurred_at', '>=', CarbonImmutable::parse($event->occurred_at)->subDay())
            ->pluck('text')
            ->all();

        if ($recent === []) {
            return;
        }

        $answer = $this->container->make(ModelRouter::class)->ask(
            user: $user,
            task: 'contradiction',
            input: implode("\n", [...$quotes, ...$recent]),
            schema: [
                'type' => 'object',
                'required' => ['findings'],
                'properties' => [
                    'findings' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['summary'],
                            'properties' => [
                                'summary' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            eventIds: [$event->id],
        );

        if ($answer->kind !== ModelAnswerKind::Accepted || ! is_array($answer->output)) {
            return;
        }

        $summary = (string) ($answer->output['findings'][0]['summary'] ?? '');

        if ($summary === '') {
            return;
        }

        $intent = CommsIntent::query()->whereBelongsTo($user)->where('opened_event_id', $event->id)->first();

        if ($intent === null) {
            return;
        }

        $this->ask->open($user, $intent, $summary, ['draft' => ''], [$event->id]);
    }

    private function person(User $user, string $name): CommsPerson
    {
        $existing = CommsPerson::query()
            ->whereBelongsTo($user)
            ->where('display_name', $name)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return CommsPerson::query()->create([
            'user_id' => $user->id,
            'display_name' => $name,
            'relation' => Relation::Collaborator,
            'importance_weight' => Relation::Collaborator->weight(),
        ]);
    }
}
