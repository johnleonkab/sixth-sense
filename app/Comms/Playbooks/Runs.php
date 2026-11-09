<?php

namespace App\Comms\Playbooks;

use App\Comms\Playbooks\Steps\Ask;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsPlaybookRun;
use App\Models\Comms\CommsProposal;

/**
 * A chained playbook stops after approval and starts again from the next
 * step when the thing it was waiting for happens. That step is code.
 */
class Runs
{
    public function __construct(private Ask $ask) {}

    public function approved(CommsProposal $proposal): void
    {
        if ($proposal->run_id === null || $proposal->user === null) {
            return;
        }

        $run = CommsPlaybookRun::query()->whereBelongsTo($proposal->user)->find($proposal->run_id);

        if ($run === null || $run->state !== 'awaiting_approval') {
            return;
        }

        $run->state = 'waiting';
        $run->step = (string) ($run->context['resume_step'] ?? 'page');
        $run->save();
    }

    public function resume(CommsEvent $event): void
    {
        $user = $event->user;
        $kind = (string) ($event->normalized['kind'] ?? '');

        if ($user === null || ! in_array($kind, ['page_edited', 'page'], true)) {
            return;
        }

        $runs = CommsPlaybookRun::query()
            ->whereBelongsTo($user)
            ->where('state', 'waiting')
            ->where('step', 'page')
            ->get();

        foreach ($runs as $run) {
            $intent = $run->intent;

            if ($intent === null) {
                continue;
            }

            $this->ask->open($user, $intent, '¿Hecho? ¿Lo envío?', ['draft' => ''], [$event->id]);
            $run->state = 'done';
            $run->step = 'asked';
            $run->save();
        }
    }
}
