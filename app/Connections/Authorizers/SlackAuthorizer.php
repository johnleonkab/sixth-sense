<?php

namespace App\Connections\Authorizers;

use App\Connections\AccountAuthorizer;
use App\Connections\AuthorizationCancelled;
use App\Connections\AuthorizedAccount;
use App\Connections\Provider;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Slack with a user token, so sixth-sense acts as the person: reads what they
 * can read and writes as them. The state is encrypted and single-use and
 * carries who started it, so the callback also works when it comes back
 * through another host (an HTTPS tunnel) without the browser session.
 *
 * @see https://docs.slack.dev/authentication/installing-with-oauth
 */
class SlackAuthorizer implements AccountAuthorizer
{
    private const AUTHORIZE_URL = 'https://slack.com/oauth/v2/authorize';

    private const API = 'https://slack.com/api';

    private const STATE_LIFETIME = 600;

    public function redirectUrl(User $user): string
    {
        $nonce = Str::random(40);
        Cache::put("slack-oauth:{$nonce}", $user->id, self::STATE_LIFETIME);

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('services.slack.client_id'),
            'user_scope' => implode(',', Provider::Slack->scopes()),
            'redirect_uri' => config('services.slack.redirect'),
            'state' => Crypt::encryptString($nonce),
        ]);
    }

    public function complete(Request $request): array
    {
        if ($request->filled('error')) {
            throw new AuthorizationCancelled;
        }

        $nonce = rescue(fn (): string => Crypt::decryptString((string) $request->query('state')), report: false);
        $userId = $nonce ? Cache::pull("slack-oauth:{$nonce}") : null;
        $user = $userId ? User::find($userId) : null;

        if ($user === null) {
            throw new RuntimeException('Slack authorisation state is missing, expired or already used.');
        }

        $token = Http::asForm()->acceptJson()->timeout(15)->post(self::API.'/oauth.v2.access', [
            'client_id' => config('services.slack.client_id'),
            'client_secret' => config('services.slack.client_secret'),
            'code' => $request->query('code'),
            'redirect_uri' => config('services.slack.redirect'),
        ]);

        if (! $token->json('ok') || blank($token->json('authed_user.access_token'))) {
            throw new RuntimeException('Slack did not authorise: '.$token->json('error', 'no user token'));
        }

        $accessToken = $token->json('authed_user.access_token');
        $slackUserId = $token->json('authed_user.id');

        $profile = Http::withToken($accessToken)->acceptJson()->timeout(15)
            ->get(self::API.'/users.info', ['user' => $slackUserId]);

        return [$user, new AuthorizedAccount(
            externalId: $token->json('team.id').':'.$slackUserId,
            email: $profile->json('user.profile.email'),
            name: $profile->json('user.profile.real_name') ?: $profile->json('user.name'),
            avatarUrl: $profile->json('user.profile.image_72'),
            accessToken: $accessToken,
            refreshToken: $token->json('authed_user.refresh_token'),
            expiresIn: $token->json('authed_user.expires_in'),
            scopes: array_values(array_filter(explode(',', (string) $token->json('authed_user.scope')))),
            metadata: [
                'team_id' => $token->json('team.id'),
                'team_name' => $token->json('team.name'),
                'user_id' => $slackUserId,
            ],
        )];
    }
}
