<?php

namespace App\Comms\Context;

/**
 * What one reader is allowed to see, already ordered and de-duplicated.
 */
final readonly class ContextPack
{
    /**
     * @param  list<array{ref: string, text: string, id: ?int}>  $rules
     * @param  list<int>  $factIds
     */
    public function __construct(
        public Audience $audience,
        public string $summary = '',
        public string $attention = '',
        public array $rules = [],
        public array $factIds = [],
    ) {}
}
