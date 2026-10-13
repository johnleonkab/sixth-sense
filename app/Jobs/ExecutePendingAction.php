<?php

namespace App\Jobs;

use App\Actions\Actions;
use App\Actions\ActionStatus;
use App\Models\PendingAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs a confirmed action once its undo window has closed.
 */
class ExecutePendingAction implements ShouldQueue
{
    use Queueable;

    /** Writing twice is worse than failing once: never retry automatically. */
    public int $tries = 1;

    /**
     * Its own queue and worker: something the person confirmed is never
     * stuck behind slow AI jobs (judging, planning) on the default queue.
     */
    public const QUEUE = 'actions';

    public function __construct(public PendingAction $action)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(Actions $actions): void
    {
        // Claim it atomically, so an "undo" racing with this job either wins or loses cleanly.
        $claimed = DB::table('pending_actions')
            ->where('id', $this->action->id)
            ->where('status', ActionStatus::Confirmed->value)
            ->update(['status' => 'executing', 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $this->action->refresh();

        try {
            $result = $actions->handlerFor($this->action)->execute($this->action);

            $this->action->update(['status' => ActionStatus::Executed, 'executed_at' => now(), 'result' => $result]);

            // A message sent from here may be the answer an alert was waiting for.
            if (in_array($this->action->type, ['mail.send', 'chat.send'], true)) {
                CheckAlertReplies::dispatch($this->action->user)->delay(now()->addSeconds(5));
            }
        } catch (Throwable $exception) {
            report($exception);

            $this->action->update(['status' => ActionStatus::Failed, 'error' => $exception->getMessage()]);
        }
    }
}
