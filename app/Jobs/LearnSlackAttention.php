<?php

namespace App\Jobs;

use App\Analytics\ProductAnalytics;
use App\Analytics\SlackTriageEvent;
use App\Connectors\Slack\SlackAttention;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rebuilds one person's Slack attention profile from the last month. Once
 * when Slack is connected, and again every week.
 */
class LearnSlackAttention implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    /** @var list<int> */
    public array $backoff = [60];

    public int $uniqueFor = 3600;

    public function __construct(public User $user) {}

    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    public function handle(SlackAttention $attention, ProductAnalytics $analytics): void
    {
        $profile = $attention->learn($this->user);

        if ($profile === null) {
            return;
        }

        $analytics->capture($this->user, SlackTriageEvent::AttentionProfileBuilt, [
            'threads' => (int) $profile->signals['threads'],
            'replied' => (int) $profile->signals['replied'],
            'missed' => (int) $profile->signals['missed'],
        ]);
    }
}
