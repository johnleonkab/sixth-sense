<?php

namespace App\Comms\Watchers;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Models\Comms\CommsCalendarEvent;
use App\Models\Comms\CommsWatcher;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The scheduler's only job: append clock events. It does not change intents.
 */
class Clock
{
    public function __construct(
        private EventLog $log,
        private BusinessCalendar $calendar,
    ) {}

    public function append(CarbonImmutable $now): int
    {
        $appended = 0;

        $watchers = CommsWatcher::query()
            ->where('state', 'active')
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<=', $now)
            ->with('user')
            ->get();

        foreach ($watchers as $watcher) {
            if ($watcher->user === null || $watcher->deadline_at === null) {
                continue;
            }

            $event = $this->log->append(
                user: $watcher->user,
                type: EventType::ClockDue,
                source: Source::Clock,
                externalId: 'due:'.$watcher->key.':'.$watcher->deadline_at->utc()->timestamp,
                payload: null,
                normalized: [
                    'watcher_key' => $watcher->key,
                    'attempt' => $watcher->attempt,
                ],
                causation: null,
                occurredAt: $now,
            );

            if ($event->wasRecentlyCreated) {
                $appended++;
            }
        }

        foreach (User::query()->cursor() as $user) {
            $local = $now->timezone($this->calendar->timezone($user));
            $clock = $local->format('H:i');
            $digests = config('comms.notify.digest_at', []);
            $digests = is_array($digests) ? $digests : [];

            if (($digests[0] ?? null) === $clock) {
                $appended += $this->mark($user, EventType::ClockStale, 'stale:'.$local->toDateString(), $now);
            }

            if (in_array($clock, $digests, true)) {
                $appended += $this->mark($user, EventType::ClockDigest, 'digest:'.$local->toDateString().':'.$clock, $now);
            }

            $appended += $this->lookahead($user, $now, $local);
        }

        return $appended;
    }

    private function lookahead(User $user, CarbonImmutable $now, CarbonImmutable $local): int
    {
        $count = 0;
        $minutes = (int) config('comms.calendar.lookahead_minutes', 20);
        $meetings = CommsCalendarEvent::query()->whereBelongsTo($user)->where('status', 'confirmed')->get();

        foreach ($meetings as $meeting) {
            $starts = $meeting->starts_at;
            $attendees = $meeting->attendees ?? [];

            if ($attendees !== [] && $starts->gte($now) && $starts->lte($now->addMinutes($minutes))) {
                $count += $this->mark($user, EventType::ClockLookahead, 'lookahead:'.$meeting->id.':'.$starts->utc()->timestamp, $now, [
                    'calendar_id' => $meeting->id,
                    'reason' => 'meeting',
                ]);
            }

            if ($meeting->kind === 'interview' && $meeting->ends_at->lte($now->subHours(2)) && $meeting->ends_at->gte($now->subHours(26))) {
                $count += $this->mark($user, EventType::ClockLookahead, 'lookahead:interview:'.$meeting->id, $now, [
                    'calendar_id' => $meeting->id,
                    'reason' => 'interview_feedback',
                ]);
            }

            if ($local->format('H:i') !== '09:00') {
                continue;
            }

            if ($meeting->kind === 'planning' && $starts->timezone($local->timezone)->toDateString() === $local->addDays((int) config('comms.calendar.planning_days', 2))->toDateString()) {
                $count += $this->mark($user, EventType::ClockLookahead, 'lookahead:planning:'.$meeting->id.':'.$local->toDateString(), $now, [
                    'calendar_id' => $meeting->id,
                    'reason' => 'meeting',
                ]);
            }

            if ($meeting->kind === 'one_on_one' && $starts->timezone($local->timezone)->isSameDay($local)) {
                $count += $this->mark($user, EventType::ClockLookahead, 'lookahead:1on1:'.$meeting->id.':'.$local->toDateString(), $now, [
                    'calendar_id' => $meeting->id,
                    'reason' => 'meeting',
                ]);
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function mark(User $user, EventType $type, string $externalId, CarbonImmutable $now, array $extra = []): int
    {
        $event = $this->log->append(
            user: $user,
            type: $type,
            source: Source::Clock,
            externalId: $externalId,
            payload: null,
            normalized: ['at' => $now->utc()->toIso8601String(), ...$extra],
            causation: null,
            occurredAt: $now,
        );

        return $event->wasRecentlyCreated ? 1 : 0;
    }
}
