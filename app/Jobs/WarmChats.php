<?php

namespace App\Jobs;

use App\Connectors\Chat\Chats;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Learns the user's chat workspaces ahead of time (people, conversations and
 * who they talk to most) so names they say are recognised at once.
 */
class WarmChats implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public User $user) {}

    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    public function handle(Chats $chats): void
    {
        $chats->warm($this->user);
    }
}
