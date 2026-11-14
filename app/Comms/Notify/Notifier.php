<?php

namespace App\Comms\Notify;

use App\Comms\Calendar\FocusHours;
use App\Comms\Gate\ActionClass;
use App\Comms\People\Relation;
use App\Comms\Pipeline;
use App\Comms\Watchers\BusinessCalendar;
use App\Events\CommsNotificationDelivered;
use App\Events\CommsNotificationUpdated;
use App\Jobs\SendCommsPush;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

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

        $updated = $this->updateOpenAlert($user, $kind, $urgency, $subjectType, $subjectId, $sourceEventIds);

        if ($updated !== null) {
            return $updated;
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

        return $this->deliver($notification);
    }

    /**
     * Dismisses this alert and any other row that is the same intent on screen.
     */
    public function dismissGroup(User $user, CommsNotification $notification): void
    {
        if ($notification->dismissed_at !== null) {
            return;
        }

        $notification->dismissed_at = now();
        $notification->save();

        $intentId = $this->intentId($notification->subject_type, $notification->subject_id);

        if ($intentId === null) {
            return;
        }

        $class = $this->proposalClass($notification);

        foreach ($this->openAlerts($user, $intentId) as $other) {
            $sameAlert = $other->kind === 'signal'
                || ($notification->kind === 'question' && $other->kind === 'question')
                || ($class !== null && $this->proposalClass($other) === $class);

            if (! $sameAlert) {
                continue;
            }

            $other->dismissed_at = $notification->dismissed_at;
            $other->save();
        }
    }

    /**
     * A follow-up or a draft for an intent that already has an alert updates
     * that alert. A different action keeps its own.
     *
     * @param  list<int>  $sourceEventIds
     */
    private function updateOpenAlert(
        User $user,
        string $kind,
        string $urgency,
        ?string $subjectType,
        ?int $subjectId,
        array $sourceEventIds,
    ): ?CommsNotification {
        $intentId = $this->intentId($subjectType, $subjectId);

        if ($intentId === null) {
            return null;
        }

        $open = $this->openAlerts($user, $intentId);

        if ($kind === 'signal' && $subjectType === 'intent') {
            $current = $open->first(
                fn (CommsNotification $notification): bool => in_array($notification->kind, ['signal', 'proposal', 'question'], true),
            );

            return $current === null ? null : $this->refresh($current, $urgency, $sourceEventIds);
        }

        if ($kind === 'proposal' && $subjectId !== null) {
            $class = CommsProposal::query()->find($subjectId)?->action_class;
            $same = $class === null ? null : $open->first(
                fn (CommsNotification $notification): bool => $this->proposalClass($notification) === $class,
            );

            if ($same !== null) {
                return $this->become($user, $same, $intentId, $kind, $urgency, $subjectType, $subjectId, $sourceEventIds);
            }

            $signal = $open->first(fn (CommsNotification $notification): bool => $notification->kind === 'signal');

            return $signal === null ? null : $this->become($user, $signal, $intentId, $kind, $urgency, $subjectType, $subjectId, $sourceEventIds);
        }

        if ($kind === 'question') {
            $question = $open->first(fn (CommsNotification $notification): bool => $notification->kind === 'question');

            if ($question !== null) {
                return $this->become($user, $question, $intentId, $kind, $urgency, $subjectType, $subjectId, $sourceEventIds);
            }

            $signal = $open->first(fn (CommsNotification $notification): bool => $notification->kind === 'signal');

            return $signal === null ? null : $this->become($user, $signal, $intentId, $kind, $urgency, $subjectType, $subjectId, $sourceEventIds);
        }

        return null;
    }

    /**
     * @param  list<int>  $sourceEventIds
     */
    private function refresh(CommsNotification $notification, string $urgency, array $sourceEventIds): CommsNotification
    {
        $events = $this->mergeEvents($notification, $sourceEventIds);
        $nextUrgency = $notification->urgency === 'now' || $urgency === 'now' ? 'now' : $urgency;
        $changed = $notification->source_event_ids !== $events || $notification->urgency !== $nextUrgency;
        $wasNow = $notification->urgency === 'now';
        $notification->source_event_ids = $events;
        $notification->urgency = $nextUrgency;
        $notification->save();

        if ($changed) {
            $this->publish($notification, ! $wasNow && $nextUrgency === 'now');
        }

        return $notification;
    }

    /**
     * @param  list<int>  $sourceEventIds
     */
    private function become(
        User $user,
        CommsNotification $notification,
        int $intentId,
        string $kind,
        string $urgency,
        ?string $subjectType,
        ?int $subjectId,
        array $sourceEventIds,
    ): CommsNotification {
        $wasNow = $notification->urgency === 'now';
        $notification->kind = $kind;
        $notification->urgency = $urgency;
        $notification->subject_type = $subjectType;
        $notification->subject_id = $subjectId;
        $notification->source_event_ids = $this->mergeEvents($notification, $sourceEventIds);
        $notification->save();
        $this->retireSignals($user, $intentId, $notification);
        $this->publish($notification, ! $wasNow && $urgency === 'now');

        return $notification;
    }

    private function retireSignals(User $user, int $intentId, CommsNotification $keep): void
    {
        foreach ($this->openAlerts($user, $intentId) as $other) {
            if ($other->id === $keep->id || $other->kind !== 'signal') {
                continue;
            }

            $other->dismissed_at = now();
            $other->save();
        }
    }

    private function deliver(CommsNotification $notification): CommsNotification
    {
        $source = $this->pipeline->sourceOf($notification);

        if ($source !== null && $this->pipeline->enabled($source) && $notification->delivered_at === null) {
            $notification->delivered_at = now();
            $notification->save();
            CommsNotificationDelivered::dispatch($notification);
        }

        return $notification;
    }

    private function publish(CommsNotification $notification, bool $push): void
    {
        $source = $this->pipeline->sourceOf($notification);

        if ($source === null || ! $this->pipeline->enabled($source)) {
            return;
        }

        if ($notification->delivered_at === null) {
            $this->deliver($notification);

            return;
        }

        if ($push) {
            SendCommsPush::dispatch($notification);
        }

        CommsNotificationUpdated::dispatch($notification);
    }

    /**
     * @return Collection<int, CommsNotification>
     */
    private function openAlerts(User $user, int $intentId): Collection
    {
        $proposalIds = CommsProposal::query()->where('intent_id', $intentId)->pluck('id');
        $questionIds = CommsQuestion::query()->where('intent_id', $intentId)->pluck('id');

        return CommsNotification::query()
            ->whereBelongsTo($user)
            ->whereNull('dismissed_at')
            ->where(function ($query) use ($intentId, $proposalIds, $questionIds): void {
                $query->where(fn ($sub) => $sub->where('subject_type', 'intent')->where('subject_id', $intentId));

                if ($proposalIds->isNotEmpty()) {
                    $query->orWhere(fn ($sub) => $sub->where('subject_type', 'proposal')->whereIn('subject_id', $proposalIds->all()));
                }

                if ($questionIds->isNotEmpty()) {
                    $query->orWhere(fn ($sub) => $sub->where('subject_type', 'question')->whereIn('subject_id', $questionIds->all()));
                }
            })
            ->orderByDesc('id')
            ->get();
    }

    private function intentId(?string $subjectType, ?int $subjectId): ?int
    {
        if ($subjectId === null) {
            return null;
        }

        $id = match ($subjectType) {
            'intent' => $subjectId,
            'proposal' => CommsProposal::query()->whereKey($subjectId)->value('intent_id'),
            'question' => CommsQuestion::query()->whereKey($subjectId)->value('intent_id'),
            default => null,
        };

        return is_numeric($id) ? (int) $id : null;
    }

    private function proposalClass(CommsNotification $notification): ?ActionClass
    {
        if ($notification->subject_type !== 'proposal' || $notification->subject_id === null) {
            return null;
        }

        return CommsProposal::query()->find($notification->subject_id)?->action_class;
    }

    /**
     * @param  list<int>  $sourceEventIds
     * @return list<int>
     */
    private function mergeEvents(CommsNotification $notification, array $sourceEventIds): array
    {
        $ids = [];

        foreach ([...($notification->source_event_ids ?? []), ...$sourceEventIds] as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
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

    public function urgencyForIntent(User $user, ?CommsIntent $intent, ?CarbonImmutable $at = null): string
    {
        if ($intent === null) {
            return 'digest';
        }

        $moment = $at ?? CarbonImmutable::now();
        $urgent = (bool) ($intent->flags['urgent'] ?? false);
        $important = $intent->importance >= (int) config('comms.notify.followup_importance');
        $soon = $intent->state !== 'settled'
            && $intent->deadline !== null
            && CarbonImmutable::parse($intent->deadline)->endOfDay()->lessThanOrEqualTo($moment->addHours(24));

        if (! $urgent && ! $important && ! $soon) {
            return 'digest';
        }

        return $urgent || $this->calendar->isWorking($user, $moment) ? 'now' : 'digest';
    }

    public function urgencyForProposal(User $user, ?CommsIntent $intent, ?CarbonImmutable $at = null): string
    {
        $moment = $at ?? CarbonImmutable::now();

        if ($intent !== null && ($intent->flags['urgent'] ?? false) === true) {
            return 'now';
        }

        return $this->calendar->isWorking($user, $moment) ? 'now' : 'digest';
    }

    private function urgentSubject(?string $type, ?int $id): bool
    {
        if ($id === null) {
            return false;
        }

        $intentId = match ($type) {
            'intent' => $id,
            'proposal' => CommsProposal::query()->whereKey($id)->value('intent_id'),
            'question' => CommsQuestion::query()->whereKey($id)->value('intent_id'),
            default => null,
        };

        return $intentId !== null && (bool) (CommsIntent::query()->find($intentId)?->flags['urgent'] ?? false);
    }
}
