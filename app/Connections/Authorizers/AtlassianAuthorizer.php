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
 * Jira through Atlassian's OAuth 2.0 (3LO): the person picks a site and
 * sixth-sense acts as them there. The state is encrypted and single-use and
 * carries who started it, so the callback also works through a tunnel.
 *
 * @see https://developer.atlassian.com/cloud/jira/platform/oauth-2-3lo-apps/
 */
class AtlassianAuthorizer implements AccountAuthorizer
{
    private const AUTHORIZE_URL = 'https://auth.atlassian.com/authorize';

    private const TOKEN_URL = 'https://auth.atlassian.com/oauth/token';

    private const STATE_LIFETIME = 600;

    public function redirectUrl(User $user): string
    {
        $nonce = Str::random(40);
        Cache::put("atlassian-oauth:{$nonce}", $user->id, self::STATE_LIFETIME);

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'audience' => 'api.atlassian.com',
            'client_id' => config('services.atlassian.client_id'),
            // Who the person is, and a refresh token: on top of what Jira needs.
            'scope' => implode(' ', [...Provider::Atlassian->scopes(), 'read:me', 'offline_access']),
            'redirect_uri' => config('services.atlassian.redirect'),
            'state' => Crypt::encryptString($nonce),
            'response_type' => 'code',
            'prompt' => 'consent',
        ], encoding_type: PHP_QUERY_RFC3986);
    }

    public function complete(Request $request): array
    {
        if ($request->filled('error')) {
            throw new AuthorizationCancelled;
        }

        $nonce = rescue(fn (): string => Crypt::decryptString((string) $request->query('state')), report: false);
        $userId = $nonce ? Cache::pull("atlassian-oauth:{$nonce}") : null;
        $user = $userId ? User::find($userId) : null;

        if ($user === null) {
            throw new RuntimeException('Atlassian authorisation state is missing, expired or already used.');
        }

        $token = Http::acceptJson()->timeout(15)->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'client_id' => config('services.atlassian.client_id'),
            'client_secret' => config('services.atlassian.client_secret'),
            'code' => $request->query('code'),
            'redirect_uri' => config('services.atlassian.redirect'),
        ]);

        if ($token->failed() || blank($token->json('access_token'))) {
            throw new RuntimeException('Atlassian did not authorise: '.($token->json('error') ?? "HTTP {$token->status()}"));
        }

        $accessToken = $token->json('access_token');
        $me = Http::withToken($accessToken)->acceptJson()->timeout(15)->get('https://api.atlassian.com/me');

        // The Jira sites this authorisation reaches; the person picked one on the consent screen.
        $sites = collect(Http::withToken($accessToken)->acceptJson()->timeout(15)->get('https://api.atlassian.com/oauth/token/accessible-resources')->json() ?? [])
            ->filter(fn (array $site): bool => collect($site['scopes'] ?? [])->contains(fn (string $scope): bool => str_contains($scope, 'jira')))
            ->values();

        $site = $sites->first() ?? throw new RuntimeException('The Atlassian account has no Jira site.');

        return [$user, new AuthorizedAccount(
            externalId: $site['id'].':'.$me->json('account_id'),
            email: $me->json('email'),
            name: $me->json('name'),
            avatarUrl: $me->json('picture'),
            accessToken: $accessToken,
            refreshToken: $token->json('refresh_token'),
            expiresIn: $token->json('expires_in'),
            scopes: array_values(array_filter(explode(' ', (string) $token->json('scope')))),
            metadata: [
                'cloud_id' => $site['id'],
                'site_name' => $site['name'] ?? null,
                'site_url' => $site['url'] ?? null,
                'account_id' => $me->json('account_id'),
                'other_sites' => $sites->slice(1)->map(fn (array $other): array => ['id' => $other['id'], 'name' => $other['name'] ?? null])->values()->all(),
            ],
        )];
    }
}
