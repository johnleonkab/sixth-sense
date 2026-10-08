<?php

namespace App\Alerts;

use App\Connectors\Calendar\CalendarEvent;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\EventQuery;
use App\Events\InboxItemCreated;
use App\Models\InboxItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plans a reminder for each upcoming meeting and raises it when it is due.
 */
class MeetingReminders
{
    /** How far ahead meetings are planned. */
    public const LOOKAHEAD_HOURS = 3;

    public function __construct(
        private CalendarReader $calendar,
        private MeetingAdvisor $advisor,
    ) {}

    /**
     * Plan reminders for the user's meetings in the next hours. A meeting is
     * only judged once per start time; moved meetings are planned again and
     * cancelled ones are dropped.
     *
     * @return int Meetings with a reminder planned.
     */
    public function plan(User $user): int
    {
        $now = CarbonImmutable::now();
        $until = $now->addHours(self::LOOKAHEAD_HOURS);
        $reading = $this->calendar->read($user, new EventQuery($now, $until));
        $planned = [];

        foreach ($reading->events as $event) {
            if (! $this->isMeetingToRemind($event, $now, $until)) {
                continue;
            }

            $key = ($event->uniqueKey ?? $event->id).'@'.$event->start->toIso8601String();
            $planned[] = $key;

            if ($user->meetingReminders()->where('event_key', $key)->exists()) {
                continue;
            }

            try {
                $briefing = $this->advisor->advise($user, $event);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            $user->meetingReminders()->create([
                'event_key' => $key,
                'event_ref' => $event->ref,
                'title' => $event->title,
                'starts_at' => $event->start,
                'remind_at' => $event->start->subMinutes($briefing->leadMinutes),
                'lead_minutes' => $briefing->leadMinutes,
                'needs_preparation' => $briefing->needsPreparation,
                'preparation' => $briefing->preparation,
                'payload' => [
                    'organizer' => $event->organizer,
                    'meeting_url' => $event->meetingUrl,
                    'url' => $event->url,
                    'location' => $event->location,
                ],
            ]);
        }

        // Meetings that disappeared or moved within the window: their old reminder is dropped.
        if ($reading->failedAccounts === []) {
            $user->meetingReminders()
                ->whereNull('notified_at')
                ->whereBetween('starts_at', [$now, $until])
                ->whereNotIn('event_key', $planned)
                ->delete();
        }

        return count($planned);
    }

    /**
     * Raise the reminders that are due.
     *
     * @return list<InboxItem>
     */
    public function remindDue(User $user): array
    {
        $alerts = [];

        $due = $user->meetingReminders()
            ->whereNull('notified_at')
            ->where('remind_at', '<=', now())
            ->where('starts_at', '>', now())
            ->get();

        foreach ($due as $reminder) {
            $reminder->update(['notified_at' => now()]);

            $minutes = max(1, (int) ceil(now()->diffInSeconds($reminder->starts_at) / 60));
            $when = $minutes === 1 ? 'Empieza en 1 minuto' : "Empieza en {$minutes} minutos";

            $item = $user->inboxItems()->firstOrCreate(
                ['source' => AlertSource::Calendar, 'external_id' => $reminder->event_key],
                [
                    'external_url' => $reminder->payload['meeting_url'] ?? $reminder->payload['url'] ?? null,
                    'actor_name' => $reminder->payload['organizer'] ?? null,
                    'title' => $reminder->title,
                    'body' => $reminder->preparation,
                    'why' => $reminder->needs_preparation ? "{$when}. {$reminder->preparation}" : "{$when}.",
                    'relevance' => $reminder->needs_preparation ? 80 : 50,
                    'status' => AlertStatus::Notice,
                    'payload' => [
                        'event_ref' => $reminder->event_ref,
                        'starts_at' => $reminder->starts_at->toIso8601String(),
                        'meeting_url' => $reminder->payload['meeting_url'] ?? null,
                        'needs_preparation' => $reminder->needs_preparation,
                        'preparation' => $reminder->preparation,
                    ],
                    'received_at' => now(),
                ],
            );

            if ($item->wasRecentlyCreated) {
                InboxItemCreated::dispatch($item);
                $alerts[] = $item;
            }
        }

        return $alerts;
    }

    /**
     * Only meetings with other people the user takes part in, that have not
     * started yet and fall in the window.
     */
    private function isMeetingToRemind(CalendarEvent $event, CarbonImmutable $now, CarbonImmutable $until): bool
    {
        $takesPart = $event->isOrganizer || in_array($event->myResponse, ['accepted', 'tentative', 'needsAction'], true);
        $withOthers = count($event->attendees) > 1 || $event->meetingUrl !== null;

        if ($event->allDay || $event->declined || ! $takesPart || ! $withOthers) {
            Log::debug("Meeting not reminded: {$event->title}");

            return false;
        }

        return $event->start->greaterThan($now) && $event->start->lessThanOrEqualTo($until);
    }
}
