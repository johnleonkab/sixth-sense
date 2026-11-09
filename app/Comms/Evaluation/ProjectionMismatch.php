<?php

namespace App\Comms\Evaluation;

use RuntimeException;

class ProjectionMismatch extends RuntimeException
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $expected
     * @param  array<string, list<array<string, mixed>>>  $actual
     */
    public function __construct(array $expected, array $actual)
    {
        parent::__construct('Projections differ. Expected '.json_encode($expected).' got '.json_encode($actual).'.');
    }
}
