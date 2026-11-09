<?php

namespace App\Comms\Context;

final readonly class ContextRequest
{
    /**
     * @param  list<string>  $subjects
     */
    public function __construct(
        public Audience $audience,
        public array $subjects = [],
        public ?string $about = null,
        public ?string $callRef = null,
    ) {}
}
