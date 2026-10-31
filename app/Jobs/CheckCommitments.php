<?php

namespace App\Jobs;

use App\Alerts\Commitments\Commitments;
use App\Alerts\Commitments\TaskDeliveries;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * What the person promised in their own messages, and, the day before a
 * task is due, whether it looks delivered.
 */
class CheckCommitments implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public User $user) {}

    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    public function handle(Commitments $commitments, TaskDeliveries $deliveries): void
    {
        $commitments->check($this->user);
        $deliveries->check($this->user);
    }
}
