<?php

namespace App\Connections;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * The OAuth dance for one provider.
 */
interface AccountAuthorizer
{
    /**
     * Where to send the person to choose an account and grant access.
     */
    public function redirectUrl(User $user): string;

    /**
     * Finish the authorisation the provider sends back.
     *
     * @return array{User, AuthorizedAccount}
     *
     * @throws AuthorizationCancelled when the person declined.
     * @throws \Throwable when it failed or was forged.
     */
    public function complete(Request $request): array;
}
