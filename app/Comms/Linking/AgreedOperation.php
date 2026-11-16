<?php

namespace App\Comms\Linking;

final readonly class AgreedOperation
{
    /**
     * @param  array<string, mixed>  $operation  The first run's operation (Schemas::extract()), unclear fields set to null.
     * @param  list<string>  $unclearFields  `kind`, `responders`, `deadline`.
     * @param  int  $runs  How many runs returned it.
     */
    public function __construct(
        public array $operation,
        public AgreementStatus $status,
        public array $unclearFields = [],
        public int $runs = 0,
    ) {}

    public function op(): string
    {
        return (string) ($this->operation['op'] ?? '');
    }
}
