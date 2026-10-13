<?php

namespace App\Connectors\Calendar;

use App\Connectors\Mail\MailAddress;
use Carbon\CarbonImmutable;

final readonly class NewEvent
{
    /**
     * @param  list<MailAddress>  $attendees
     */
    public function __construct(
        public int $accountId,
        public string $title,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public array $attendees = [],
        public ?string $description = null,
        public bool $videoCall = true,
        /** A block of the person's own work, put there by the scheduler. */
        public bool $task = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'title' => $this->title,
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'timezone' => $this->start->getTimezone()->getName(),
            'attendees' => array_map(fn (MailAddress $address): array => $address->toArray(), $this->attendees),
            'description' => $this->description,
            'video_call' => $this->videoCall,
            'task' => $this->task,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accountId: (int) $data['account_id'],
            title: $data['title'],
            start: CarbonImmutable::parse($data['start'])->setTimezone($data['timezone']),
            end: CarbonImmutable::parse($data['end'])->setTimezone($data['timezone']),
            attendees: array_map(fn (array $address): MailAddress => new MailAddress($address['name'], $address['email']), $data['attendees']),
            description: $data['description'],
            videoCall: (bool) $data['video_call'],
            task: (bool) ($data['task'] ?? false),
        );
    }
}
