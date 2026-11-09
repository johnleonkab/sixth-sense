<?php

namespace App\Comms\Ingestion;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Messages staged in memory. Tests and the demo use this instead of Slack or Gmail.
 */
class FakeInboundFeed implements InboundFeed
{
    /**
     * @var list<SlackInbound|GmailInbound>
     */
    public array $items = [];

    public function collect(User $user, CarbonImmutable $since): array
    {
        return array_values(array_filter(
            $this->items,
            fn (SlackInbound|GmailInbound $item): bool => $item->at >= $since,
        ));
    }
}
