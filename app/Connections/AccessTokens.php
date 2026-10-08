<?php

namespace App\Connections;

use App\Connections\Google\GoogleTokens;
use App\Connectors\Notion\NotionTokens;
use App\Connectors\Slack\SlackTokens;
use App\Models\ConnectedAccount;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Hands out valid access tokens for connected accounts, refreshing them
 * when they are about to expire. Connectors ask here for a token; they never
 * read `access_token` directly.
 */
class AccessTokens
{
    /** Refresh tokens this many seconds before they expire. */
    private const EXPIRY_MARGIN = 60;

    public function __construct(private Container $container) {}

    /**
     * @throws AccountNeedsReconnection
     * @throws TokenRefreshFailed
     */
    public function for(ConnectedAccount $account): string
    {
        $this->ensureActive($account);

        if (! $account->accessTokenExpiresWithin(self::EXPIRY_MARGIN)) {
            return $account->access_token;
        }

        return Cache::lock("connected-account:{$account->id}:refresh", 15)->block(10, function () use ($account): string {
            // Another request may have refreshed it while we waited for the lock.
            $account->refresh();
            $this->ensureActive($account);

            if (! $account->accessTokenExpiresWithin(self::EXPIRY_MARGIN)) {
                return $account->access_token;
            }

            return $this->refresh($account);
        });
    }

    /**
     * A new access token now, for providers whose tokens give no expiry and
     * are found expired when the API refuses them. Skipped if another request
     * renewed it in the last moments.
     *
     * @throws AccountNeedsReconnection
     * @throws TokenRefreshFailed
     */
    public function renew(ConnectedAccount $account, string $refused): string
    {
        return Cache::lock("connected-account:{$account->id}:refresh", 15)->block(10, function () use ($account, $refused): string {
            $account->refresh();
            $this->ensureActive($account);

            return $account->access_token !== $refused ? $account->access_token : $this->refresh($account);
        });
    }

    /**
     * @throws AccountNeedsReconnection
     * @throws TokenRefreshFailed
     */
    private function refresh(ConnectedAccount $account): string
    {
        try {
            $token = $this->tokensFor($account->provider)->refresh($account);
        } catch (AccessRevoked) {
            $account->update(['status' => AccountStatus::NeedsReconnection]);

            throw new AccountNeedsReconnection($account);
        }

        $account->update(array_filter([
            'access_token' => $token->accessToken,
            'expires_at' => $token->expiresAt,
            'refresh_token' => $token->refreshToken,
            'scopes' => $token->scopes,
            'last_refreshed_at' => now(),
        ], fn (mixed $value): bool => $value !== null));

        return $token->accessToken;
    }

    private function ensureActive(ConnectedAccount $account): void
    {
        if ($account->status === AccountStatus::NeedsReconnection) {
            throw new AccountNeedsReconnection($account);
        }
    }

    /**
     * Revoke the authorisation at the provider, unless another user of this app
     * connected the same external account: revoking would disconnect them too.
     */
    public function revoke(ConnectedAccount $account): void
    {
        $sharedWithAnotherUser = ConnectedAccount::query()
            ->ofProvider($account->provider)
            ->where('external_account_id', $account->external_account_id)
            ->whereKeyNot($account->getKey())
            ->exists();

        if ($sharedWithAnotherUser) {
            return;
        }

        $this->tokensFor($account->provider)->revoke($account);
    }

    private function tokensFor(Provider $provider): ProviderTokens
    {
        return match ($provider) {
            Provider::Google => $this->container->make(GoogleTokens::class),
            Provider::Slack => $this->container->make(SlackTokens::class),
            Provider::Notion => $this->container->make(NotionTokens::class),
            default => throw new InvalidArgumentException("No token operations for [{$provider->value}]."),
        };
    }
}
