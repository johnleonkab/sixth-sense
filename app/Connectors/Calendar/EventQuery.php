<?php

namespace App\Connectors\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Which events to read: a range of whole days in the user's timezone,
 * optionally narrowed by title or attendee.
 */
final readonly class EventQuery
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?string $title = null,
        public ?string $attendee = null,
    ) {}

    public static function days(string $from, ?string $to, string $timezone, ?string $title = null, ?string $attendee = null): self
    {
        return new self(
            from: CarbonImmutable::parse($from, $timezone)->startOfDay(),
            to: CarbonImmutable::parse($to ?? $from, $timezone)->endOfDay(),
            title: filled($title) ? $title : null,
            attendee: filled($attendee) ? $attendee : null,
        );
    }

    public function timezone(): string
    {
        return $this->from->getTimezone()->getName();
    }

    public function isSingleDay(): bool
    {
        return $this->from->isSameDay($this->to);
    }

    public function isFiltered(): bool
    {
        return $this->title !== null || $this->attendee !== null;
    }

    public function matches(CalendarEvent $event): bool
    {
        if ($this->title !== null && ! self::contains($event->title, $this->title)) {
            return false;
        }

        if ($this->attendee === null) {
            return true;
        }

        $people = [$event->organizer, ...array_merge(...array_map(
            fn (array $attendee): array => [$attendee['name'], $attendee['email']],
            $event->attendees,
        ))];

        foreach ($people as $person) {
            if ($person !== null && self::contains($person, $this->attendee)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Case- and accent-insensitive "contains".
     */
    private static function contains(string $haystack, string $needle): bool
    {
        return Str::contains(Str::lower(Str::ascii($haystack)), Str::lower(Str::ascii($needle)));
    }
}
