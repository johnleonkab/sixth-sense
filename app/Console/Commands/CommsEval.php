<?php

namespace App\Console\Commands;

use App\Comms\Evaluation\RetrievalMetric;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('comms:eval {user? : The user id} {--task= : retrieve}')]
#[Description('Report how often the right topic and intent are in the top ten')]
class CommsEval extends Command
{
    public function handle(RetrievalMetric $metric): int
    {
        if ((string) $this->option('task') !== 'retrieve') {
            $this->components->error('Unknown task.');

            return self::FAILURE;
        }

        $user = null;

        if (filled($this->argument('user'))) {
            $user = User::query()->find($this->argument('user'));

            if ($user === null) {
                $this->components->error('User not found.');

                return self::FAILURE;
            }
        }

        $counts = $metric->measure($user);
        $this->line('topic in top 10: '.$counts['topic']['hits'].' of '.$counts['topic']['total']);
        $this->line('intent in top 10: '.$counts['intent']['hits'].' of '.$counts['intent']['total']);

        return self::SUCCESS;
    }
}
