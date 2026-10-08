<?php

namespace App\Jobs;

use App\Alerts\AlertFollowUp;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Resolves the user's pending alerts they already answered, here or in the
 * service itself.
 */
class CheckAlertReplies implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 120;

    public function __construct(public User $user) {}

    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    public function handle(AlertFollowUp $followUp): void
    {
        $followUp->checkReplies($this->user);
    }
}
