<?php

namespace App\Http\Controllers\Settings;

use App\Connections\AccountStatus;
use App\Connections\AuthorizationCancelled;
use App\Connections\AuthorizedAccount;
use App\Connections\Authorizers\AtlassianAuthorizer;
use App\Connections\Authorizers\GithubAuthorizer;
use App\Connections\Authorizers\GoogleAuthorizer;
use App\Connections\Authorizers\NotionAuthorizer;
use App\Connections\Authorizers\SlackAuthorizer;
use App\Connections\Provider;
use App\Connectors\GitHub\GithubRepositories;
use App\Http\Controllers\Controller;
use App\Jobs\LearnSlackAttention;
use App\Jobs\WarmChats;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Throwable;

/**
 * OAuth flow to connect an external account. Running it again for an account
 * that is already connected refreshes its credentials instead of duplicating it.
 */
class AccountAuthorizationController extends Controller
{
    /**
     * Send the user to the provider to choose an account and grant access.
     */
    public function redirect(Request $request, Provider $provider): RedirectResponse
    {
        abort_unless($provider->isConnectable(), 404);

        return redirect()->away($this->authorizer($provider)->redirectUrl($request->user()));
    }

    /**
     * Store the account the provider sends back.
     */
    public function callback(Request $request, Provider $provider, GithubRepositories $repositories, PostHogService $posthog): RedirectResponse
    {
        abort_unless($provider->isConnectable(), 404);

        try {
            [$user, $authorized] = $this->authorizer($provider)->complete($request);
        } catch (AuthorizationCancelled) {
            return $this->backWith($request, 'warning', "No se ha conectado ninguna cuenta de {$provider->label()}.");
        } catch (Throwable $exception) {
            report($exception);

            return $this->backWith($request, 'error', "No he podido conectar la cuenta de {$provider->label()}. Inténtalo de nuevo.");
        }

        $account = $this->store($user, $provider, $authorized);

        $posthog->capture((string) $user->getAuthIdentifier(), 'connection_authorized', [
            'provider' => $provider->value,
            'account_created' => $account->wasRecentlyCreated,
            'missing_scope_count' => count($account->missingScopes()),
        ]);

        if ($provider === Provider::Slack) {
            WarmChats::dispatch($user);
            LearnSlackAttention::dispatch($user);
        }

        if ($provider === Provider::GitHub) {
            try {
                $repositories->sync($account);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($account->missingScopes() !== []) {
            return $this->backWith($request, 'warning', "Cuenta {$account->email} conectada, pero sin todos los permisos. Vuelve a conectarla y acéptalos todos.");
        }

        return $this->backWith($request, 'success', $account->wasRecentlyCreated
            ? "Cuenta {$account->email} conectada."
            : "Cuenta {$account->email} actualizada.");
    }

    private function authorizer(Provider $provider): GoogleAuthorizer|SlackAuthorizer|NotionAuthorizer|AtlassianAuthorizer|GithubAuthorizer
    {
        return match ($provider) {
            Provider::Google => app(GoogleAuthorizer::class),
            Provider::Slack => app(SlackAuthorizer::class),
            Provider::Notion => app(NotionAuthorizer::class),
            Provider::Atlassian => app(AtlassianAuthorizer::class),
            Provider::GitHub => app(GithubAuthorizer::class),
        };
    }

    private function store(User $user, Provider $provider, AuthorizedAccount $authorized): ConnectedAccount
    {
        $attributes = [
            'email' => $authorized->email,
            'name' => $authorized->name,
            'avatar_url' => $authorized->avatarUrl,
            'access_token' => $authorized->accessToken,
            'scopes' => $authorized->scopes,
            'metadata' => $authorized->metadata ?: null,
            'expires_at' => $authorized->expiresIn ? now()->addSeconds($authorized->expiresIn) : null,
            'status' => AccountStatus::Active,
            'last_refreshed_at' => now(),
        ];

        // Providers only send a refresh token on some authorisations: keep the one we have.
        if (filled($authorized->refreshToken)) {
            $attributes['refresh_token'] = $authorized->refreshToken;
        }

        return $user->connectedAccounts()->updateOrCreate(
            ['provider' => $provider, 'external_account_id' => $authorized->externalId],
            $attributes,
        );
    }

    /**
     * @param  'success'|'warning'|'error'  $type
     */
    private function backWith(Request $request, string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        // A callback that came through a tunnel has no session there: go back to the app itself.
        return $request->user()
            ? to_route('settings.connections.index')
            : redirect()->away(rtrim((string) config('app.url'), '/').'/settings/connections');
    }
}
