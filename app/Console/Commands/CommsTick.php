<?php

namespace App\Console\Commands;

use App\Comms\Watchers\Clock;
use App\Comms\Windows\SettleBuffer;
use App\Models\Comms\CommsWindow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('comms:tick')]
#[Description('Append clock events for due watchers, the daily stale pass and the digest')]
class CommsTick extends Command
{
    public function handle(Clock $clock, SettleBuffer $buffer): int
    {
        $now = CarbonImmutable::now();
        $count = $clock->append($now);

        if (config('comms.linking.v2')) {
            $userIds = CommsWindow::query()->where('state', 'open')->distinct()->pluck('user_id');

            foreach (User::query()->whereIn('id', $userIds)->orderBy('id')->get() as $user) {
                $buffer->due($user, $now);
            }
        }

        $this->components->info("Appended {$count} clock events.");

        return self::SUCCESS;
    }
}
