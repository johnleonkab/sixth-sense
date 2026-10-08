<?php

namespace App\Connections\Authorizers;

use App\Connections\AccountAuthorizer;
use App\Connections\AuthorizationCancelled;
use App\Connections\AuthorizedAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Notion through a public connection: the person picks the pages it may use,
 * and it acts as them. The state is encrypted, single-use and carries who
 * started it, so the callback also works through an HTTPS tunnel.
 *
 * @see https://developers.notion.com/guides/get-started/authorization
 */
class NotionAuthorizer implements AccountAuthorizer
{
    private const AUTHORIZE_URL = 'https://api.notion.com/v1/oauth/authorize';

    private const TOKEN_URL = 'https://api.notion.com/v1/oauth/token';

    private const STATE_LIFETIME = 600;

    public function redirectUrl(User $user): string
    {
        $nonce = Str::random(40);
        Cache::put("notion-oauth:{$nonce}", $user->id, self::STATE_LIFETIME);

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('services.notion.client_id'),
            'redirect_uri' => config('services.notion.redirect'),
            'response_type' => 'code',
            'owner' => 'user',
            'state' => Crypt::encryptString($nonce),
        ]);
    }

    public function complete(Request $request): array
    {
        if ($request->filled('error')) {
            throw new AuthorizationCancelled;
        }

        $nonce = rescue(fn (): string => Crypt::decryptString((string) $request->query('state')), report: false);
        $userId = $nonce ? Cache::pull("notion-oauth:{$nonce}") : null;
        $user = $userId ? User::find($userId) : null;

        if ($user === null) {
            throw new RuntimeException('Notion authorisation state is missing, expired or already used.');
        }

        $token = Http::withBasicAuth((string) config('services.notion.client_id'), (string) config('services.notion.client_secret'))
            ->acceptJson()
            ->timeout(15)
            ->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $request->query('code'),
                'redirect_uri' => config('services.notion.redirect'),
            ]);

        if ($token->failed() || blank($token->json('access_token'))) {
            throw new RuntimeException('Notion did not authorise: '.($token->json('error') ?? "HTTP {$token->status()}"));
        }

        $owner = $token->json('owner.user', []);

        return [$user, new AuthorizedAccount(
            externalId: $token->json('workspace_id').':'.($owner['id'] ?? $token->json('bot_id')),
            email: $owner['person']['email'] ?? null,
            name: $owner['name'] ?? null,
            avatarUrl: $owner['avatar_url'] ?? null,
            accessToken: $token->json('access_token'),
            refreshToken: $token->json('refresh_token'),
            expiresIn: $token->json('expires_in'),
            scopes: [],
            metadata: array_filter([
                'workspace_id' => $token->json('workspace_id'),
                'workspace_name' => $token->json('workspace_name'),
                'workspace_icon' => $token->json('workspace_icon'),
                'bot_id' => $token->json('bot_id'),
                'user_id' => $owner['id'] ?? null,
            ]),
        )];
    }
}
