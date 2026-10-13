<?php

namespace App\Console\Commands;

use App\Alerts\Triage;
use App\Jobs\JudgeInboxItem;
use App\Models\InboxItem;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alerts:unstick')]
#[Description('Judge again alerts left judging (a lost job, the queue down for a while), and let rules decide the oldest')]
class UnstickAlerts extends Command
{
    /** Judging longer than this: its job is queued again (a job still alive keeps it, being unique). */
    public const RETRY_AFTER_MINUTES = 5;

    /** Judging longer than this: simple rules decide, as when the AI runs out of tries. */
    public const DECIDE_AFTER_MINUTES = 20;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $requeued = 0;
        $decided = 0;

        InboxItem::query()
            ->where('triage', Triage::Judging)
            ->where('updated_at', '<', now()->subMinutes(self::RETRY_AFTER_MINUTES))
            ->each(function (InboxItem $item) use (&$requeued, &$decided): void {
                if ($item->updated_at->lt(now()->subMinutes(self::DECIDE_AFTER_MINUTES))) {
                    (new JudgeInboxItem($item))->failed(null);
                    $decided++;

                    return;
                }

                JudgeInboxItem::dispatch($item);
                $requeued++;
            });

        $this->components->info("{$requeued} en cola de nuevo, {$decided} decididas por reglas.");

        return self::SUCCESS;
    }
}
