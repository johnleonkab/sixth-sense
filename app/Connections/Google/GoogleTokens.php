<?php

namespace App\Connections\Google;

use App\Connections\AccessRevoked;
use App\Connections\ProviderTokens;
use App\Connections\RefreshedToken;
use App\Connections\TokenRefreshFailed;
use App\Models\ConnectedAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * @see https://developers.google.com/identity/protocols/oauth2/web-server
 */
class GoogleTokens implements ProviderTokens
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public function refresh(ConnectedAccount $account): RefreshedToken
    {
        if (blank($account->refresh_token)) {
            throw new AccessRevoked('The account has no refresh token.');
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(10)->post(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
            ]);
        } catch (ConnectionException $exception) {
            throw new TokenRefreshFailed($exception->getMessage(), previous: $exception);
        }

        if ($response->json('error') === 'invalid_grant') {
            throw new AccessRevoked((string) $response->json('error_description', 'invalid_grant'));
        }

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new TokenRefreshFailed("Google token refresh failed with HTTP {$response->status()}: {$response->json('error')}");
        }

        return new RefreshedToken(
            accessToken: $response->json('access_token'),
            expiresAt: $response->json('expires_in') ? CarbonImmutable::now()->addSeconds((int) $response->json('expires_in')) : null,
            refreshToken: $response->json('refresh_token'),
            scopes: $response->json('scope') ? explode(' ', $response->json('scope')) : null,
        );
    }

    public function revoke(ConnectedAccount $account): void
    {
        $token = $account->refresh_token ?? $account->access_token;

        try {
            $response = Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $token]);
        } catch (ConnectionException $exception) {
            throw new TokenRefreshFailed($exception->getMessage(), previous: $exception);
        }

        // 400 means the token was already invalid: there is nothing left to revoke.
        if ($response->serverError()) {
            throw new TokenRefreshFailed("Google token revocation failed with HTTP {$response->status()}.");
        }
    }
}
