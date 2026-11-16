<?php

namespace App\Console\Commands;

use App\Comms\Backfill\BackfillEval;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('comms:backfill-eval {user : The user id} {--critic= : gemini or deepseek}')]
#[Description('Score the linking pipeline on a clone of the last 30 days')]
class CommsBackfillEval extends Command
{
    public function handle(BackfillEval $eval): int
    {
        $user = User::query()->find($this->argument('user'));

        if (! $user instanceof User) {
            $this->components->error('User not found.');

            return self::FAILURE;
        }

        $critic = $this->option('critic');
        $critic = is_string($critic) && $critic !== '' ? $critic : null;

        if ($critic !== null && ! in_array($critic, ['gemini', 'deepseek'], true)) {
            $this->components->error('Unknown critic. Use gemini or deepseek.');

            return self::FAILURE;
        }

        $report = $eval->run($user, $critic);
        $needs = $report['needs_user'];
        $latency = $report['latency_ms'];

        $this->components->info('Evaluation written to '.$report['report_path']);
        $this->line(sprintf(
            'Needs the user: precision %s, recall %s (tp %d, fp %d, fn %d, tn %d).',
            $this->rate($needs['precision']),
            $this->rate($needs['recall']),
            $needs['tp'],
            $needs['fp'],
            $needs['fn'],
            $needs['tn'],
        ));
        $this->line(sprintf(
            'Uncertain %s. Critic refuted %d of %d. Tokens/window %s. Cost/window %s. Latency p50 %s ms, p95 %s ms.',
            $this->rate($report['uncertain_share']),
            $report['critic']['refuted'],
            $report['critic']['checked'],
            $this->rate($report['tokens_per_window']),
            $this->rate($report['cost_per_window']),
            $this->rate($latency['p50']),
            $this->rate($latency['p95']),
        ));

        return self::SUCCESS;
    }

    private function rate(mixed $value): string
    {
        return is_int($value) || is_float($value) ? (string) $value : 'n/a';
    }
}
