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
 * GitHub as the person, with the repo scope so private repositories can be
 * read and, where the person allows it, updated. The state is encrypted and
 * single-use and carries who started it, so the callback also works when it
 * comes back through another host without the browser session.
 *
 * @see https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps
 */
class GithubAuthorizer implements AccountAuthorizer
{
    private const AUTHORIZE_URL = 'https://github.com/login/oauth/authorize';

    private const TOKEN_URL = 'https://github.com/login/oauth/access_token';

    private const API = 'https://api.github.com';

    private const STATE_LIFETIME = 600;

    public function redirectUrl(User $user): string
    {
        $nonce = Str::random(40);
        Cache::put("github-oauth:{$nonce}", $user->id, self::STATE_LIFETIME);

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('services.github.client_id'),
            'redirect_uri' => config('services.github.redirect'),
            'scope' => implode(' ', [...Provider::GitHub->scopes(), 'read:user', 'user:email']),
            'state' => Crypt::encryptString($nonce),
        ]);
    }

    public function complete(Request $request): array
    {
        if ($request->filled('error')) {
            throw new AuthorizationCancelled;
        }

        $nonce = rescue(fn (): string => Crypt::decryptString((string) $request->query('state')), report: false);
        $userId = $nonce ? Cache::pull("github-oauth:{$nonce}") : null;
        $user = $userId ? User::find($userId) : null;

        if ($user === null) {
            throw new RuntimeException('GitHub authorisation state is missing, expired or already used.');
        }

        $token = Http::acceptJson()->timeout(15)->post(self::TOKEN_URL, [
            'client_id' => config('services.github.client_id'),
            'client_secret' => config('services.github.client_secret'),
            'code' => $request->query('code'),
            'redirect_uri' => config('services.github.redirect'),
        ]);

        if ($token->failed() || blank($token->json('access_token'))) {
            throw new RuntimeException('GitHub did not authorise: '.$token->json('error', 'no access token'));
        }

        $accessToken = $token->json('access_token');
        $profile = Http::withToken($accessToken)->acceptJson()->timeout(15)
            ->withHeaders($this->headers())
            ->get(self::API.'/user');

        if ($profile->failed() || blank($profile->json('id'))) {
            throw new RuntimeException('GitHub did not return the authorised user.');
        }

        return [$user, new AuthorizedAccount(
            externalId: (string) $profile->json('id'),
            email: $profile->json('email') ?: $this->primaryEmail($accessToken),
            name: $profile->json('name') ?: $profile->json('login'),
            avatarUrl: $profile->json('avatar_url'),
            accessToken: $accessToken,
            refreshToken: $token->json('refresh_token'),
            expiresIn: filled($token->json('expires_in')) ? (int) $token->json('expires_in') : null,
            scopes: array_values(array_filter(preg_split('/[\s,]+/', (string) $token->json('scope')) ?: [])),
            metadata: [
                'login' => $profile->json('login'),
            ],
        )];
    }

    private function primaryEmail(string $accessToken): ?string
    {
        $emails = Http::withToken($accessToken)->acceptJson()->timeout(15)
            ->withHeaders($this->headers())
            ->get(self::API.'/user/emails');

        $primary = collect($emails->json())->first(fn (mixed $email): bool => is_array($email) && ($email['primary'] ?? false) === true);

        return is_array($primary) ? ($primary['email'] ?? null) : null;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'sixth-sense',
            'X-GitHub-Api-Version' => '2026-03-10',
        ];
    }
}
