<?php

namespace App\Console\Commands;

use App\Comms\Ingestion\InboundFeed;
use App\Comms\Ingestion\Ingestor;
use App\Connections\Provider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('comms:reconcile {user? : The user id}')]
#[Description('Append Slack and Gmail messages missed since the last cursor')]
class CommsReconcile extends Command
{
    public function handle(InboundFeed $feed, Ingestor $ingestor): int
    {
        $users = filled($this->argument('user'))
            ? User::query()->whereKey($this->argument('user'))->get()
            : User::query()->whereHas('connectedAccounts')->get();

        if (filled($this->argument('user')) && $users->isEmpty()) {
            $this->components->error('User not found.');

            return self::FAILURE;
        }

        foreach ($users as $user) {
            $cursor = $this->cursor($user);
            $ingestor->ingestAll($user, $feed->collect($user, $cursor));
            $this->advance($user);
        }

        $this->components->info('Reconciliation appended.');

        return self::SUCCESS;
    }

    private function cursor(User $user): CarbonImmutable
    {
        $stored = null;

        foreach ($user->connectedAccounts as $account) {
            $value = $account->metadata['comms_cursor'] ?? null;

            if (is_string($value) && ($stored === null || $value < $stored)) {
                $stored = $value;
            }
        }

        $start = $stored !== null ? CarbonImmutable::parse($stored) : CarbonImmutable::now()->subMinutes(15);

        return $start->subMinutes(2);
    }

    private function advance(User $user): void
    {
        $now = now()->toIso8601String();

        foreach ($user->connectedAccounts as $account) {
            if (! in_array($account->provider, [Provider::Slack, Provider::Google], true)) {
                continue;
            }

            $metadata = $account->metadata ?? [];
            $metadata['comms_cursor'] = $now;
            $account->metadata = $metadata;
            $account->save();
        }
    }
}
