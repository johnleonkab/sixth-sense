<?php

namespace App\Console\Commands;

use App\Comms\Metrics\CutoverReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('comms:report {user : The user id} {--date= : The day to count, YYYY-MM-DD} {--triggers : Count router outcomes with no playbook}')]
#[Description('Print one day of communications cutover numbers')]
class CommsReport extends Command
{
    public function handle(CutoverReport $report): int
    {
        $user = User::query()->find($this->argument('user'));

        if ($user === null) {
            $this->components->error('User not found.');

            return self::FAILURE;
        }

        $day = filled($this->option('date'))
            ? CarbonImmutable::parse((string) $this->option('date'))
            : CarbonImmutable::yesterday();

        foreach ($report->lines($user, $day, (bool) $this->option('triggers')) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
