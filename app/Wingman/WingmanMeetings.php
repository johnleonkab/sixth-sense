<?php

namespace App\Wingman;

use App\Connectors\Calendar\CalendarEvent;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\EventQuery;
use App\Jobs\PrepareWingmanContext;
use App\Models\User;
use App\Models\WingmanMeeting;
use Carbon\CarbonImmutable;

/**
 * Today's Google Meet calls of the person, from every Google account they
 * connected with calendar access. Moved meetings are found again; cancelled
 * ones that have not started are dropped. Those about to start get their
 * context prepared.
 */
class WingmanMeetings
{
    public function __construct(private CalendarReader $calendar) {}

    /**
     * @return int Meetings of today with a Meet link.
     */
    public function sync(User $user): int
    {
        $timezone = (string) config('agenda.timezone');
        $now = CarbonImmutable::now($timezone);
        $reading = $this->calendar->read($user, new EventQuery($now->startOfDay(), $now->endOfDay()));
        $found = [];

        foreach ($reading->events as $event) {
            $code = WingmanMeeting::codeIn($event->meetingUrl);

            if ($code === null || $event->allDay || $event->declined || $event->myResponse === 'declined') {
                continue;
            }

            $meeting = $this->keep($user, $event, $code);
            $found[] = $meeting->event_key;

            // About to start, or going on: its context is gathered now. Ended ones are left as they are.
            if ($meeting->context_prepared_at === null
                && $meeting->starts_at->lte($now->addMinutes((int) config('wingman.prepare_before_minutes')))
                && $meeting->ends_at->gt($now)) {
                PrepareWingmanContext::dispatch($meeting);
            }
        }

        // Gone from the calendar before starting: cancelled or moved to another day.
        if ($reading->failedAccounts === []) {
            $user->wingmanMeetings()
                ->where('status', MeetingStatus::Scheduled)
                ->whereBetween('starts_at', [$now->startOfDay()->utc(), $now->endOfDay()->utc()])
                ->whereNotIn('event_key', $found)
                ->delete();
        }

        return count($found);
    }

    private function keep(User $user, CalendarEvent $event, string $code): WingmanMeeting
    {
        $meeting = $user->wingmanMeetings()->firstOrNew(['event_key' => ($event->uniqueKey ?? $event->id).'@'.$event->start->utc()->toIso8601String()]);

        $meeting->fill([
            'event_ref' => $event->ref,
            'meeting_code' => $code,
            'meeting_url' => $event->meetingUrl,
            'title' => $event->title,
            'account' => $event->account,
            'organizer' => $event->organizer,
            'starts_at' => $event->start->utc(),
            'ends_at' => $event->end->utc(),
            'attendees' => $event->attendees,
            'description' => $event->description,
        ]);

        // What the context was gathered from changed: gather it again.
        if ($meeting->exists && $meeting->isDirty(['title', 'description', 'attendees'])) {
            $meeting->context_prepared_at = null;
        }

        $meeting->save();

        return $meeting;
    }
}
