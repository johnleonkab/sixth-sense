<?php

namespace App\Comms\Context;

use Carbon\CarbonImmutable;

/**
 * One document of the knowledge corpus, as the comms playbooks read it.
 * `kind` is `code` for the work archive, otherwise the source.
 */
final readonly class CorpusDocument
{
    public function __construct(
        public string $ref,
        public string $kind,
        public string $title,
        public string $body,
        public ?string $topic,
        public CarbonImmutable $updatedAt,
        public string $terms = '',
    ) {}
}
