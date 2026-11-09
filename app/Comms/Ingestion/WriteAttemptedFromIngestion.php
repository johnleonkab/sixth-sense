<?php

namespace App\Comms\Ingestion;

use RuntimeException;

/**
 * Ingestion tried to call a Slack method that can change something.
 */
final class WriteAttemptedFromIngestion extends RuntimeException
{
    public function __construct(public string $method)
    {
        parent::__construct("Ingestion cannot call [{$method}].");
    }
}
