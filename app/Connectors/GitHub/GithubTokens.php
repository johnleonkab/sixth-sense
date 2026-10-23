<?php

namespace App\Connectors\GitHub;

use App\Connections\AccessRevoked;
use App\Connections\ProviderTokens;
use App\Connections\RefreshedToken;
use App\Models\ConnectedAccount;
use Illuminate\Support\Facades\Http;

/**
 * OAuth app user tokens do not expire, so there is nothing to refresh: an
 * invalid token means connecting again.
 *
 * @see https://docs.github.com/en/rest/apps/oauth-applications#delete-an-app-token
 */
class GithubTokens implements ProviderTokens
{
    public function refresh(ConnectedAccount $account): RefreshedToken
    {
        throw new AccessRevoked('GitHub user tokens are not refreshed.');
    }

    public function revoke(ConnectedAccount $account): void
    {
        $clientId = (string) config('services.github.client_id');

        Http::acceptJson()
            ->withBasicAuth($clientId, (string) config('services.github.client_secret'))
            ->timeout(10)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'sixth-sense',
                'X-GitHub-Api-Version' => '2026-03-10',
            ])
            ->delete("https://api.github.com/applications/{$clientId}/grant", [
                'access_token' => $account->access_token,
            ]);
    }
}
