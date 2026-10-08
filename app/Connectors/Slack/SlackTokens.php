<?php

namespace App\Connectors\Slack;

use App\Connections\AccessRevoked;
use App\Connections\ProviderTokens;
use App\Connections\RefreshedToken;
use App\Models\ConnectedAccount;
use Illuminate\Support\Facades\Http;

/**
 * Slack user tokens do not expire (token rotation is off), so there is
 * nothing to refresh: an invalid token means connecting again.
 */
class SlackTokens implements ProviderTokens
{
    public function refresh(ConnectedAccount $account): RefreshedToken
    {
        throw new AccessRevoked('Slack user tokens are not refreshed.');
    }

    public function revoke(ConnectedAccount $account): void
    {
        Http::withToken($account->access_token)->acceptJson()->timeout(10)->post('https://slack.com/api/auth.revoke');
    }
}
