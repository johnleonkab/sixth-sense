<?php

namespace App\Console\Commands;

use App\Comms\Evaluation\PromptChange;
use App\Comms\Evaluation\RetrievalMetric;
use App\Comms\Evaluation\WeeklyEval;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('comms:eval {user? : The user id} {--task= : retrieve} {--weekly} {--prompt-change}')]
#[Description('Report the golden set, and refuse a prompt change that makes a weekly metric worse')]
class CommsEval extends Command
{
    public function handle(RetrievalMetric $metric, WeeklyEval $weekly, PromptChange $change): int
    {
        $user = $this->user();

        if ($user === false) {
            return self::FAILURE;
        }

        if ($this->option('prompt-change')) {
            $worse = $change->worse($user);

            if ($worse === []) {
                $this->components->info('No metric got worse.');

                return self::SUCCESS;
            }

            $this->components->error('Worse: '.implode(', ', $worse));

            return self::FAILURE;
        }

        if ($this->option('weekly')) {
            $run = $weekly->record($user);
            $this->components->info("Weekly eval {$run->id} stored.");

            return self::SUCCESS;
        }

        if ((string) $this->option('task') !== 'retrieve') {
            $this->components->error('Unknown task.');

            return self::FAILURE;
        }

        $counts = $metric->measure($user);
        $this->line('topic in top 10: '.$counts['topic']['hits'].' of '.$counts['topic']['total']);
        $this->line('intent in top 10: '.$counts['intent']['hits'].' of '.$counts['intent']['total']);

        return self::SUCCESS;
    }

    private function user(): User|null|false
    {
        if (! filled($this->argument('user'))) {
            return null;
        }

        $user = User::query()->find($this->argument('user'));

        if ($user === null) {
            $this->components->error('User not found.');

            return false;
        }

        return $user;
    }
}
