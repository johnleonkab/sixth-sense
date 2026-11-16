<?php

namespace App\Comms\Linking;

/**
 * What the linker is allowed to choose for one segment.
 */
final readonly class Candidates
{
    /**
     * @param  list<string>  $topics  Topic subjects, best first, at most ten.
     * @param  list<int>  $intents  Intent ids, hard links first, at most ten.
     * @param  list<string>  $ranking  `intent:{id}` and `topic:{subject}`, hard links first.
     */
    public function __construct(
        public array $topics,
        public array $intents,
        public array $ranking,
    ) {}
}
