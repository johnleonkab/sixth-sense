<?php

namespace App\Console\Commands;

use App\Alerts\AlertDigest;
use App\Alerts\AlertStatus;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alerts:digest')]
#[Description('Gather the quiet alerts still waiting into one notice')]
class SendAlertDigests extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AlertDigest $digest): int
    {
        $gathered = 0;

        User::query()
            ->whereHas('inboxItems', fn ($items) => $items->where('status', AlertStatus::Pending))
            ->each(function (User $user) use ($digest, &$gathered): void {
                $gathered += count($digest->send($user));
            });

        $this->components->info("{$gathered} avisos en el resumen.");

        return self::SUCCESS;
    }
}
