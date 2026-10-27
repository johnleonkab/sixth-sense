<?php

namespace App\Jobs;

use App\Alerts\InboxCandidate;
use App\Alerts\Intents\IntentAssigner;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Decides which intent a new message belongs to, away from the watcher, so a
 * slow model never stops the inbox moving.
 */
class AssignIntent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    /**
     * @var list<int>
     */
    public array $backoff = [20, 60];

    public int $uniqueFor = 900;

    /**
     * @param  array<string, mixed>  $candidate
     * @param  list<array{id: string, from: ?string, text: string, at: string, source?: string}>  $earlier
     */
    public function __construct(
        public int $userId,
        public array $candidate,
        public array $earlier = [],
    ) {}

    public function uniqueId(): string
    {
        return $this->userId.':'.$this->candidate['external_id'];
    }

    public function handle(IntentAssigner $assigner): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        $assigner->assign($user, InboxCandidate::fromArray($this->candidate), $this->earlier);
    }

    /**
     * Out of tries: rules still place the message, so an outage does not drop an ask.
     */
    public function failed(?Throwable $exception): void
    {
        $user = User::query()->find($this->userId);

        if ($user !== null) {
            app(IntentAssigner::class)->assign($user, InboxCandidate::fromArray($this->candidate), $this->earlier, byRules: true);
        }
    }
}
