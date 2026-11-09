<?php

namespace App\Comms\Ingestion;

use App\Connections\Capability;
use App\Connections\Provider;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Reads connected Slack and Gmail accounts. The cursor is the caller's $since.
 */
class AccountInboundFeed implements InboundFeed
{
    public function __construct(
        private SlackHistory $slack,
        private GmailHistory $gmail,
    ) {}

    public function collect(User $user, CarbonImmutable $since): array
    {
        $items = [];

        foreach ($user->connectedAccounts as $account) {
            if ($account->provider === Provider::Slack) {
                array_push($items, ...$this->slack->collect($account, $since));
            }

            if ($account->provider === Provider::Google && $account->allows(Capability::GmailRead)) {
                array_push($items, ...$this->gmail->collect($account, $since));
            }
        }

        return $items;
    }
}
