<?php

namespace App\Comms\Ingestion;

use App\Models\User;
use Carbon\CarbonImmutable;

interface InboundFeed
{
    /**
     * @return list<SlackInbound|GmailInbound>
     */
    public function collect(User $user, CarbonImmutable $since): array;
}
