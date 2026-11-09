<?php

namespace App\Comms\Watchers;

use App\Comms\Events\EventType;
use App\Comms\Gate\ProposalExpiry;
use App\Comms\Intents\ImportanceCalculator;
use App\Comms\Notify\Notifier;
use App\Comms\Playbooks\Briefing;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsQuestion;
use App\Models\Comms\CommsWatcher;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Applies a clock event. The same path runs live and on replay, so a model
 * is never asked from here.
 */
class ClockHandler
{
    public function __construct(
        private ConditionEvaluator $evaluator,
        private BusinessCalendar $calendar,
        private ImportanceCalculator $importance,
        private Notifier $notifier,
        private ProposalExpiry $expiry,
        private Briefing $briefing,
    ) {}

    public function apply(CommsEvent $event): void
    {
        match ($event->type) {
            EventType::ClockDue => $this->due($event),
            EventType::ClockStale => $this->stale($event),
            EventType::ClockDigest => $this->digest($event),
            EventType::ClockLookahead => $this->briefing->apply($event),
            default => null,
        };
    }

    private function due(CommsEvent $event): void
    {
        $user = $event->user;
        $key = $event->normalized['watcher_key'] ?? null;

        if ($user === null || ! is_string($key)) {
            return;
        }

        $watcher = CommsWatcher::query()->whereBelongsTo($user)->where('key', $key)->first();

        if ($watcher === null || $watcher->state !== 'active') {
            return;
        }

        if ($watcher->attempt !== (int) ($event->normalized['attempt'] ?? 0)) {
            return;
        }

        $intent = $watcher->intent;

        if ($intent !== null) {
            $intent->importance = $this->importance->score($intent);
            $intent->save();
        }

        if ($this->evaluator->met($user, $watcher)) {
            $watcher->state = 'met';
            $watcher->save();

            return;
        }

        if ($watcher->attempt >= 2) {
            $watcher->state = 'cancelled';
            $watcher->save();

            if ($intent !== null && $intent->state === 'open') {
                $intent->state = 'stale';
                $intent->last_change_summary = 'Sin respuesta tras dos avisos';
                $intent->save();
            }

            return;
        }

        $question = $this->question($watcher, $event);
        $this->notifier->question($user, $question, $event, $this->urgency($watcher, $event));

        $watcher->attempt++;
        $watcher->deadline_at = $this->calendar->addDays($user, CarbonImmutable::parse($event->occurred_at), 3);
        $watcher->state = 'active';
        $watcher->save();
    }

    private function stale(CommsEvent $event): void
    {
        $user = $event->user;

        if ($user === null) {
            return;
        }

        $now = CarbonImmutable::parse($event->occurred_at);
        $stale = [];

        foreach (CommsIntent::query()->whereBelongsTo($user)->where('state', 'open')->get() as $intent) {
            $intent->importance = $this->importance->score($intent);
            $intent->save();

            if ($this->expired($intent, $now)) {
                $intent->state = 'expired';
                $intent->save();

                continue;
            }

            if ($this->goneQuiet($user, $intent, $now)) {
                $intent->state = 'stale';
                $intent->save();
                $stale[] = $intent;
            }
        }

        if ($stale !== []) {
            $this->notifier->batch($user, $stale, $event);
        }
    }

    private function digest(CommsEvent $event): void
    {
        if ($event->user === null) {
            return;
        }

        CommsNotification::query()
            ->whereBelongsTo($event->user)
            ->where('urgency', 'digest')
            ->whereNull('delivered_at')
            ->update(['delivered_at' => $event->occurred_at]);

        $this->expiry->expire($event->user);
    }

    private function question(CommsWatcher $watcher, CommsEvent $event): CommsQuestion
    {
        $intent = $watcher->intent;
        $expects = (string) ($intent->expects ?? 'lo que pediste');
        $text = $watcher->attempt === 0
            ? 'Sigue sin llegar: '.$expects.'. ¿Lo reclamo?'
            : 'Sigue sin llegar. ¿Se lo pido a otra persona o por otro canal?';

        return CommsQuestion::query()->updateOrCreate(
            ['user_id' => $watcher->user_id, 'key' => 'q:'.$event->id],
            [
                'intent_id' => $watcher->intent_id,
                'text' => $text,
                'context' => [
                    'draft' => '',
                    'expects' => $expects,
                    'attempt' => $watcher->attempt + 1,
                ],
                'options' => $watcher->attempt === 0
                    ? [['id' => 'ask', 'label' => 'Reclamar'], ['id' => 'wait', 'label' => 'Esperar']]
                    : [['id' => 'other', 'label' => 'Otro camino'], ['id' => 'drop', 'label' => 'Dejarlo']],
            ],
        );
    }

    private function urgency(CommsWatcher $watcher, CommsEvent $event): string
    {
        $intent = $watcher->intent;
        $urgent = (bool) ($intent->flags['urgent'] ?? false);
        $secondMiss = $watcher->attempt >= 1 && ($intent->importance ?? 0) >= (int) config('comms.notify.second_miss_importance');
        $now = $urgent || $secondMiss;

        if (! $now) {
            return 'digest';
        }

        if ($urgent || $event->user === null || $this->calendar->isWorking($event->user, CarbonImmutable::parse($event->occurred_at))) {
            return 'now';
        }

        return 'digest';
    }

    private function expired(CommsIntent $intent, CarbonImmutable $now): bool
    {
        if ($intent->importance >= (int) config('comms.notify.expire_importance')) {
            return false;
        }

        $pastDeadline = $intent->deadline !== null
            && CarbonImmutable::parse($intent->deadline)->startOfDay()->lt($now->startOfDay());
        $anchor = $intent->last_activity_at ?? $intent->created_at;
        $old = $anchor !== null
            && CarbonImmutable::parse($anchor)->lte($now->subDays((int) config('comms.notify.expire_after_days')));

        return $pastDeadline || $old;
    }

    private function goneQuiet(User $user, CommsIntent $intent, CarbonImmutable $now): bool
    {
        if ($intent->deadline !== null && CarbonImmutable::parse($intent->deadline)->startOfDay()->lt($now->startOfDay())) {
            return true;
        }

        $latest = $intent->links()->with('message')->get()->max(fn ($link) => $link->message?->occurred_at);
        $anchor = $latest ?? $intent->last_activity_at ?? $intent->created_at;

        if ($anchor === null) {
            return false;
        }

        $limit = $this->calendar->addDays($user, CarbonImmutable::parse($anchor), (int) config('comms.notify.stale_after_business_days'));

        return $now->greaterThanOrEqualTo($limit);
    }
}
