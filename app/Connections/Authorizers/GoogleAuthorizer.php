<?php

namespace App\Connections\Authorizers;

use App\Connections\AccountAuthorizer;
use App\Connections\AuthorizationCancelled;
use App\Connections\AuthorizedAccount;
use App\Connections\Provider;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OAuthUser;

/**
 * Google, through Socialite: offline access so tokens can be refreshed, and
 * always asking which account, so several can be connected.
 */
class GoogleAuthorizer implements AccountAuthorizer
{
    public function redirectUrl(User $user): string
    {
        return Socialite::driver('google')
            ->scopes(Provider::Google->scopes())
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent select_account',
                'include_granted_scopes' => 'true',
            ])
            ->redirect()
            ->getTargetUrl();
    }

    public function complete(Request $request): array
    {
        if ($request->filled('error')) {
            throw new AuthorizationCancelled;
        }

        $user = $request->user() ?? throw new AuthenticationException;

        /** @var OAuthUser $oauthUser */
        $oauthUser = Socialite::driver('google')->user();

        return [$user, new AuthorizedAccount(
            externalId: (string) $oauthUser->getId(),
            email: $oauthUser->getEmail(),
            name: $oauthUser->getName(),
            avatarUrl: $oauthUser->getAvatar(),
            accessToken: $oauthUser->token,
            refreshToken: $oauthUser->refreshToken,
            expiresIn: $oauthUser->expiresIn,
            scopes: array_values(array_filter($oauthUser->approvedScopes)),
        )];
    }
}
