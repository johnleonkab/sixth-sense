<?php

namespace App\Console\Commands;

use App\Comms\Watchers\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('comms:tick')]
#[Description('Append clock events for due watchers, the daily stale pass and the digest')]
class CommsTick extends Command
{
    public function handle(Clock $clock): int
    {
        $count = $clock->append(CarbonImmutable::now());
        $this->components->info("Appended {$count} clock events.");

        return self::SUCCESS;
    }
}
