<?php

namespace App\Connectors\Calendar;

use Carbon\CarbonImmutable;

/**
 * A calendar event, already in the user's timezone, from any account.
 */
final readonly class CalendarEvent
{
    /**
     * @param  list<array{name: string, email: ?string, response?: ?string}>  $attendees
     * @param  list<array{title: ?string, url: string}>  $documents  Google Docs attached or linked.
     * @param  ?string  $ref  Identifies the event across accounts and calendars, to change it.
     * @param  ?string  $myResponse  accepted, declined, tentative or needsAction, when the user is invited.
     */
    public function __construct(
        public string $id,
        public string $title,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public bool $allDay,
        public string $account,
        public ?string $location = null,
        public array $attendees = [],
        public ?string $organizer = null,
        public bool $declined = false,
        public ?string $meetingUrl = null,
        public ?string $url = null,
        public ?string $uniqueKey = null,
        public ?string $calendar = null,
        public ?string $calendarColor = null,
        public array $documents = [],
        public ?string $ref = null,
        public ?string $description = null,
        public bool $isOrganizer = false,
        public ?string $myResponse = null,
    ) {}

    /**
     * Data the calendar canvases render.
     *
     * @return array<string, mixed>
     */
    public function toCanvas(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'all_day' => $this->allDay,
            'location' => $this->location,
            'attendees' => $this->attendees,
            'account' => $this->account,
            'calendar' => $this->calendar,
            'calendar_color' => $this->calendarColor,
            'declined' => $this->declined,
            'documents' => $this->documents,
            'event_id' => $this->ref,
            'meeting_url' => $this->meetingUrl,
            'url' => $this->url,
        ];
    }

    /**
     * Compact description for the voice assistant.
     *
     * @return array<string, mixed>
     */
    public function toAssistant(bool $includeAccount, bool $includeCalendar = false): array
    {
        return array_filter([
            'title' => $this->title,
            'date' => $this->start->toDateString(),
            'weekday' => $this->start->locale('es')->isoFormat('dddd'),
            'start' => $this->allDay ? null : $this->start->format('H:i'),
            'end' => $this->allDay ? null : $this->end->format('H:i'),
            'all_day' => $this->allDay ?: null,
            'location' => $this->location,
            'attendees' => array_slice(array_column($this->attendees, 'name'), 0, 8) ?: null,
            'declined_by_user' => $this->declined ?: null,
            'account' => $includeAccount ? $this->account : null,
            'calendar' => $includeCalendar ? $this->calendar : null,
            'documents' => $this->documents ?: null,
            'event_id' => $this->ref,
            'organized_by_user' => $this->isOrganizer ?: null,
            'my_response' => $this->myResponse,
        ], fn (mixed $value): bool => $value !== null);
    }
}
