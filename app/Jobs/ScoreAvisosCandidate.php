<?php

namespace App\Jobs;

use App\Avisos\Candidate;
use App\Avisos\Engine;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scores one inbound aviso away from the webhook request.
 */
class ScoreAvisosCandidate implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 600;

    /**
     * @param  array<string, mixed>  $candidate
     */
    public function __construct(public int $userId, public array $candidate) {}

    public function uniqueId(): string
    {
        return $this->userId.':'.($this->candidate['source'] ?? '').':'.($this->candidate['external_id'] ?? '');
    }

    public function handle(Engine $engine): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        $engine->score($user, Candidate::fromArray($this->candidate));
    }
}
