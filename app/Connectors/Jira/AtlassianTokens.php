<?php

namespace App\Connectors\Jira;

use App\Connections\AccessRevoked;
use App\Connections\ProviderTokens;
use App\Connections\RefreshedToken;
use App\Connections\TokenRefreshFailed;
use App\Models\ConnectedAccount;
use Illuminate\Support\Facades\Http;

/**
 * Atlassian access tokens last an hour; refresh tokens rotate: each refresh
 * returns a new one, and the old one stops working.
 *
 * @see https://developer.atlassian.com/cloud/jira/platform/oauth-2-3lo-apps/#use-a-refresh-token-to-get-another-access-token-and-refresh-token-pair
 */
class AtlassianTokens implements ProviderTokens
{
    public function refresh(ConnectedAccount $account): RefreshedToken
    {
        if (blank($account->refresh_token)) {
            throw new AccessRevoked('This Atlassian account has no refresh token.');
        }

        $response = Http::acceptJson()->timeout(15)->post('https://auth.atlassian.com/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => config('services.atlassian.client_id'),
            'client_secret' => config('services.atlassian.client_secret'),
            'refresh_token' => $account->refresh_token,
        ]);

        if ($response->json('error') === 'invalid_grant' || $response->status() === 403) {
            throw new AccessRevoked("Atlassian refused the refresh token: {$response->json('error_description')}");
        }

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new TokenRefreshFailed("Atlassian token refresh failed with HTTP {$response->status()}.");
        }

        return new RefreshedToken(
            accessToken: $response->json('access_token'),
            expiresAt: now()->toImmutable()->addSeconds((int) ($response->json('expires_in') ?? 3600)),
            refreshToken: $response->json('refresh_token'),
            scopes: array_values(array_filter(explode(' ', (string) $response->json('scope')))) ?: null,
        );
    }

    public function revoke(ConnectedAccount $account): void
    {
        // Atlassian has no endpoint to revoke a 3LO grant: the person removes the app from their account settings.
    }
}
