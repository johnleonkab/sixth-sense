<?php

namespace App\Alerts;

use App\Connectors\Google\GmailWatcher;
use App\Connectors\Slack\SlackWatcher;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The watcher of each source that raises alerts about messages.
 */
class InboxWatchers
{
    public function __construct(private Container $container) {}

    public function for(AlertSource $source): InboxWatcher
    {
        return match ($source) {
            AlertSource::Gmail => $this->container->make(GmailWatcher::class),
            AlertSource::Slack => $this->container->make(SlackWatcher::class),
            default => throw new InvalidArgumentException("{$source->value} has no inbox to watch."),
        };
    }
}
