<?php

namespace App\Comms\Models;

interface CompletesTasks
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function complete(string $provider, string $tier, string $system, string $input, array $schema): ModelCompletion;
}
