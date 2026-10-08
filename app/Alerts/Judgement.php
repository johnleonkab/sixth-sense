<?php

namespace App\Alerts;

final readonly class Judgement
{
    /**
     * @param  string  $reason  Why it matters, in one short sentence for the person.
     * @param  int  $relevance  0 to 100.
     */
    public function __construct(
        public bool $important,
        public string $reason,
        public int $relevance = 0,
    ) {}
}
