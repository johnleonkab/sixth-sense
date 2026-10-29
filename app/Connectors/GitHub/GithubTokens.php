<?php

namespace App\Connectors\GitHub;

use App\Connections\AccessRevoked;
use App\Connections\ProviderTokens;
use App\Connections\RefreshedToken;
use App\Connections\TokenRefreshFailed;
use App\Models\ConnectedAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * When the OAuth app expires user tokens, the access token lasts eight hours
 * and the refresh token rotates. A token issued without expiry has nothing to refresh.
 *
 * @see https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/refreshing-user-access-tokens
 */
class GithubTokens implements ProviderTokens
{
    public function refresh(ConnectedAccount $account): RefreshedToken
    {
        if (blank($account->refresh_token)) {
            throw new AccessRevoked('This GitHub account has no refresh token.');
        }

        $response = Http::asForm()->acceptJson()->timeout(15)->post('https://github.com/login/oauth/access_token', [
            'client_id' => config('services.github.client_id'),
            'client_secret' => config('services.github.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);

        $fields = self::fields($response);

        if (($fields['error'] ?? null) === 'bad_refresh_token') {
            throw new AccessRevoked('GitHub refused the refresh token.');
        }

        if ($response->failed() || blank($fields['access_token'] ?? null)) {
            throw new TokenRefreshFailed("GitHub token refresh failed with HTTP {$response->status()}.");
        }

        $scopes = array_values(array_filter(preg_split('/[\s,]+/', (string) ($fields['scope'] ?? '')) ?: []));

        return new RefreshedToken(
            accessToken: $fields['access_token'],
            expiresAt: filled($fields['expires_in'] ?? null) ? now()->toImmutable()->addSeconds((int) $fields['expires_in']) : null,
            refreshToken: $fields['refresh_token'] ?? null,
            scopes: $scopes === [] ? null : $scopes,
        );
    }

    /**
     * GitHub answers the token endpoint as JSON when asked, and as a form
     * otherwise. A refresh token is single use, so a form body still has to
     * yield the replacement or the next call is refused for good.
     *
     * @return array<string, mixed>
     */
    public static function fields(Response $response): array
    {
        $json = $response->json();

        if (is_array($json) && (array_key_exists('access_token', $json) || array_key_exists('error', $json))) {
            return $json;
        }

        $fields = [];
        parse_str($response->body(), $fields);

        return $fields;
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
