<?php

namespace App\Connectors\Google;

/**
 * "{account id}:{calendar id}:{event id}". Calendar ids may contain dots and
 *
 * @ (they are often emails) but never colons.
 */
final readonly class GoogleEventRef
{
    public function __construct(
        public int $accountId,
        public string $calendarId,
        public string $eventId,
    ) {}

    public static function make(int $accountId, string $calendarId, string $eventId): string
    {
        return "{$accountId}:{$calendarId}:{$eventId}";
    }

    public static function parse(string $ref): ?self
    {
        $parts = explode(':', $ref);

        if (count($parts) !== 3 || ! ctype_digit($parts[0]) || $parts[1] === '' || $parts[2] === '') {
            return null;
        }

        return new self((int) $parts[0], $parts[1], $parts[2]);
    }
}
