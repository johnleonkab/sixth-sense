<?php

namespace App\Comms\Linking;

/**
 * One operation after the critic. `refuted` is true only when a quote in the
 * messages or the dossier contradicts it.
 */
final readonly class CriticReview
{
    public function __construct(
        public AgreedOperation $operation,
        public bool $refuted,
    ) {}
}
