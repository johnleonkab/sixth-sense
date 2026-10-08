<?php

namespace App\Connectors\Calendar;

use Carbon\CarbonImmutable;

/**
 * Changes to an existing event: only what is set changes.
 */
final readonly class EventUpdate
{
    /**
     * @param  list<string>  $removeAttendees  Emails of the people to take off the invitation.
     * @param  bool  $notify  Tell the attendees about the change.
     */
    public function __construct(
        public ?CarbonImmutable $start = null,
        public ?CarbonImmutable $end = null,
        public ?string $title = null,
        public ?string $location = null,
        public ?string $description = null,
        public array $removeAttendees = [],
        public bool $notify = true,
    ) {}

    public function isEmpty(): bool
    {
        return $this->start === null && $this->end === null && $this->title === null && $this->location === null
            && $this->description === null && $this->removeAttendees === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start?->toIso8601String(),
            'end' => $this->end?->toIso8601String(),
            'title' => $this->title,
            'location' => $this->location,
            'description' => $this->description,
            'remove_attendees' => $this->removeAttendees,
            'notify' => $this->notify,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            start: isset($data['start']) ? CarbonImmutable::parse($data['start']) : null,
            end: isset($data['end']) ? CarbonImmutable::parse($data['end']) : null,
            title: $data['title'] ?? null,
            location: $data['location'] ?? null,
            description: $data['description'] ?? null,
            removeAttendees: $data['remove_attendees'] ?? [],
            notify: (bool) ($data['notify'] ?? true),
        );
    }
}
