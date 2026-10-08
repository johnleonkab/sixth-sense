<?php

namespace App\Connections;

use App\Models\ConnectedAccount;

/**
 * Provider-specific token operations. One implementation per provider.
 */
interface ProviderTokens
{
    /**
     * Exchange the account's refresh token for a new access token.
     *
     * @throws AccessRevoked when the provider will never accept this refresh token again.
     * @throws TokenRefreshFailed when the refresh failed but may work on a later attempt.
     */
    public function refresh(ConnectedAccount $account): RefreshedToken;

    /**
     * Tell the provider to forget the authorisation behind this account.
     *
     * @throws TokenRefreshFailed when the provider could not be reached.
     */
    public function revoke(ConnectedAccount $account): void;
}
