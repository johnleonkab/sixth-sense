<?php

namespace App\Comms\Notify;

use App\Comms\Calendar\FocusHours;
use App\Comms\People\Relation;
use App\Comms\Pipeline;
use App\Comms\Watchers\BusinessCalendar;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsQuestion;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Stores what the system would say. Shadow mode does not push it.
 */
class Notifier
{
    public function __construct(
        private BusinessCalendar $calendar,
        private Pipeline $pipeline,
        private FocusHours $focus,
    ) {}

    public function onState(User $user, CommsEvent $event): void
    {
        if (($event->causation?->normalized['backfill'] ?? false) === true) {
            return;
        }

        foreach ($event->normalized['results'] ?? [] as $result) {
            if (! is_array($result) || ($result['status'] ?? '') !== 'accepted') {
                continue;
            }

            if (($result['op'] ?? '') === 'signal') {
                $this->store($user, 'signal', 'digest', null, null, 'n:'.$event->id.':signal', [$event->id]);

                continue;
            }

            if (! is_string($result['intent_key'] ?? null)) {
                continue;
            }

            $intent = CommsIntent::query()->whereBelongsTo($user)->where('key', $result['intent_key'])->first();

            if ($intent === null || ! $this->participates($user, $intent)) {
                continue;
            }

            $urgency = $this->immediate($user, $intent, $result, $event);

            if ($urgency === null) {
                continue;
            }

            $this->store($user, 'signal', $urgency, 'intent', $intent->id, 'n:'.$event->id.':'.$intent->key, [$event->id]);
        }
    }

    public function question(User $user, CommsQuestion $question, CommsEvent $event, string $urgency): void
    {
        $this->store($user, 'question', $urgency, 'question', $question->id, 'n:'.$event->id, [$event->id]);
    }

    /**
     * @param  list<CommsIntent>  $intents
     */
    public function batch(User $user, array $intents, CommsEvent $event): void
    {
        $rows = array_map(fn (CommsIntent $intent): array => [
            'key' => $intent->key,
            'what' => $intent->what,
        ], $intents);

        $question = CommsQuestion::query()->updateOrCreate(
            ['user_id' => $user->id, 'key' => 'q:'.$event->id],
            [
                'intent_id' => null,
                'text' => '¿Siguen abiertas?',
                'context' => ['draft' => '', 'intents' => $rows],
                'options' => array_map(fn (array $row): array => [
                    'key' => $row['key'],
                    'actions' => ['keep', 'drop'],
                ], $rows),
            ],
        );

        $this->store($user, 'batch', 'batch', 'question', $question->id, 'n:'.$event->id, [$event->id]);
    }

    public function applyAnswer(CommsEvent $event): void
    {
        if ($event->user === null) {
            return;
        }

        $data = $event->normalized ?? [];

        if (($data['kind'] ?? '') === 'draft' && is_string($data['question_key'] ?? null)) {
            $question = CommsQuestion::query()->whereBelongsTo($event->user)->where('key', $data['question_key'])->first();

            if ($question === null) {
                return;
            }

            $context = $question->context ?? [];
            $context['draft'] = (string) ($data['draft'] ?? '');
            $question->context = $context;
            $question->save();

            return;
        }

        if (($data['kind'] ?? '') !== 'feedback' || ! is_string($data['notification_key'] ?? null)) {
            return;
        }

        $notification = CommsNotification::query()
            ->whereBelongsTo($event->user)
            ->where('key', $data['notification_key'])
            ->first();

        if ($notification === null) {
            return;
        }

        $feedback = $data['feedback'] ?? null;
        $notification->feedback = is_string($feedback) && $feedback !== '' && $feedback !== 'useful' ? $feedback : null;
        $notification->seen_at = $event->occurred_at;
        $notification->save();
    }

    /**
     * @param  list<int>  $sourceEventIds
     */
    public function store(
        User $user,
        string $kind,
        string $urgency,
        ?string $subjectType,
        ?int $subjectId,
        string $key,
        array $sourceEventIds,
    ): CommsNotification {
        if ($urgency === 'now' && ! $this->urgentSubject($subjectType, $subjectId) && $this->focus->covers($user, CarbonImmutable::now())) {
            $urgency = 'digest';
        }

        $notification = CommsNotification::query()->updateOrCreate(
            ['user_id' => $user->id, 'key' => $key],
            [
                'kind' => $kind,
                'urgency' => $urgency,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'source_event_ids' => $sourceEventIds,
            ],
        );
        $source = $this->pipeline->sourceOf($notification);

        if ($source !== null && $this->pipeline->enabled($source) && $notification->delivered_at === null) {
            $notification->delivered_at = now();
            $notification->save();
        }

        return $notification;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function immediate(User $user, CommsIntent $intent, array $result, CommsEvent $event): ?string
    {
        $selfId = CommsPerson::query()->whereBelongsTo($user)->where('relation', Relation::Self)->value('id');
        $intent->loadMissing('participants');
        $userIsResponder = $selfId !== null && $intent->participants->contains(
            fn ($participant): bool => $participant->role === 'responder' && $participant->person_id === $selfId,
        );
        $urgent = (bool) ($intent->flags['urgent'] ?? false);
        $becameResponder = $userIsResponder && ($result['op'] ?? '') === 'open' && $intent->importance >= (int) config('comms.notify.followup_importance');
        $soon = false;

        if ($userIsResponder && $intent->state !== 'settled' && $intent->deadline !== null) {
            $due = CarbonImmutable::parse($intent->deadline)->endOfDay();
            $soon = $due->lessThanOrEqualTo(CarbonImmutable::parse($event->occurred_at)->addHours(24));
        }

        if (! $urgent && ! $becameResponder && ! $soon) {
            return null;
        }

        if ($urgent || $this->calendar->isWorking($user, CarbonImmutable::parse($event->occurred_at))) {
            return 'now';
        }

        return 'digest';
    }

    private function participates(User $user, CommsIntent $intent): bool
    {
        $selfId = CommsPerson::query()->whereBelongsTo($user)->where('relation', Relation::Self)->value('id');
        $intent->loadMissing('participants');

        return $selfId !== null && $intent->participants->contains(
            fn ($participant): bool => $participant->person_id === $selfId,
        );
    }

    private function urgentSubject(?string $type, ?int $id): bool
    {
        if ($type !== 'intent' || $id === null) {
            return false;
        }

        return (bool) (CommsIntent::query()->find($id)?->flags['urgent'] ?? false);
    }
}
