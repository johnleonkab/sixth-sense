<?php

namespace App\Console\Commands;

use App\Jobs\PromoteUnansweredSlack as PromoteUnansweredSlackJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('slack:promote-unanswered')]
#[Description('Raise a reply when a question in a channel she owns is still alone')]
class PromoteUnansweredSlack extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        PromoteUnansweredSlackJob::dispatch();

        $this->components->info('Revisión de canales propios en cola.');

        return self::SUCCESS;
    }
}
