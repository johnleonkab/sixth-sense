<?php

namespace App\Connectors\Notion;

use App\Connections\AccessRevoked;
use App\Connections\ProviderTokens;
use App\Connections\RefreshedToken;
use App\Connections\TokenRefreshFailed;
use App\Models\ConnectedAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Notion access tokens are refreshed with the refresh token, which rotates.
 *
 * @see https://developers.notion.com/reference/refresh-a-token
 */
class NotionTokens implements ProviderTokens
{
    private const TOKEN_URL = 'https://api.notion.com/v1/oauth/token';

    private const REVOKE_URL = 'https://api.notion.com/v1/oauth/revoke';

    public function refresh(ConnectedAccount $account): RefreshedToken
    {
        if (blank($account->refresh_token)) {
            throw new AccessRevoked('This Notion account has no refresh token.');
        }

        $response = $this->client()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);

        if (in_array($response->json('error'), ['invalid_grant', 'unauthorized_client'], true)) {
            throw new AccessRevoked("Notion refused the refresh token: {$response->json('error')}");
        }

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new TokenRefreshFailed("Notion token refresh failed with HTTP {$response->status()}.");
        }

        return new RefreshedToken(
            accessToken: $response->json('access_token'),
            expiresAt: $response->json('expires_in') ? now()->toImmutable()->addSeconds((int) $response->json('expires_in')) : null,
            refreshToken: $response->json('refresh_token'),
        );
    }

    public function revoke(ConnectedAccount $account): void
    {
        $this->client()->post(self::REVOKE_URL, ['token' => $account->access_token]);
    }

    private function client(): PendingRequest
    {
        return Http::withBasicAuth((string) config('services.notion.client_id'), (string) config('services.notion.client_secret'))
            ->acceptJson()
            ->timeout(15);
    }
}
