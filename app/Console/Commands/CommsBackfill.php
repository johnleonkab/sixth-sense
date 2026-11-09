<?php

namespace App\Console\Commands;

use App\Comms\Ingestion\InboundFeed;
use App\Comms\Ingestion\Ingestor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('comms:backfill {user : The user id} {--days=14 : How many days to read}')]
#[Description('Append in-scope Slack and Gmail messages from before activation')]
class CommsBackfill extends Command
{
    public function handle(InboundFeed $feed, Ingestor $ingestor): int
    {
        $user = User::query()->find($this->argument('user'));

        if (! $user instanceof User) {
            $this->components->error('User not found.');

            return self::FAILURE;
        }

        $since = CarbonImmutable::now()->subDays((int) $this->option('days'));
        $ingestor->ingestAll($user, $feed->collect($user, $since), backfill: true);
        $this->components->info('Backfill appended.');

        return self::SUCCESS;
    }
}
