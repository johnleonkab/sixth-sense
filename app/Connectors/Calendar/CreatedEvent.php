<?php

namespace App\Connectors\Calendar;

/**
 * An event just created: how to change it later, and its link.
 */
final readonly class CreatedEvent
{
    public function __construct(
        public string $ref,
        public ?string $url = null,
    ) {}
}
