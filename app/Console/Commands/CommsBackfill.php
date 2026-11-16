<?php

namespace App\Console\Commands;

use App\Comms\Backfill\Passes;
use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Ingestion\InboundFeed;
use App\Comms\Ingestion\Ingestor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('comms:backfill {user : The user id} {--days= : How many days to read} {--passes=all : identity,labels,topics,patterns,sweep}')]
#[Description('Append in-scope Slack and Gmail messages from before activation')]
class CommsBackfill extends Command
{
    public function handle(InboundFeed $feed, Ingestor $ingestor, EventLog $log, Passes $passes): int
    {
        $user = User::query()->find($this->argument('user'));

        if (! $user instanceof User) {
            $this->components->error('User not found.');

            return self::FAILURE;
        }

        $selected = $passes->select((string) $this->option('passes'));

        if ($selected === null) {
            $this->components->error('Unknown backfill pass. Use identity, labels, topics, patterns, sweep.');

            return self::FAILURE;
        }

        $days = $this->option('days');
        $days = $days === null || $days === '' ? (int) config('comms.backfill.days') : (int) $days;
        $ingestor->ingestAll($user, $feed->collect($user, CarbonImmutable::now()->subDays($days)), backfill: true);

        foreach ($selected as $pass) {
            $log->append(
                user: $user,
                type: EventType::BackfillCompleted,
                source: Source::User,
                externalId: 'backfill:'.$pass,
                payload: null,
                normalized: ['pass' => $pass, 'counts' => $passes->run($user, $pass)],
                causation: null,
                occurredAt: CarbonImmutable::now(),
            );
        }

        $this->components->info('Backfill appended.');

        return self::SUCCESS;
    }
}
