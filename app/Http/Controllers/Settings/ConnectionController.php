<?php

namespace App\Http\Controllers\Settings;

use App\Connections\AccessTokens;
use App\Connections\Capability;
use App\Connections\Provider;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ConnectionController extends Controller
{
    /**
     * List every provider with the accounts the user has connected to it.
     */
    public function index(Request $request): Response
    {
        /** @var Collection<string, Collection<int, ConnectedAccount>> $accounts */
        $accounts = $request->user()->connectedAccounts()->oldest()->get()
            ->groupBy(fn (ConnectedAccount $account): string => $account->provider->value);

        return Inertia::render('settings/connections', [
            'providers' => collect(Provider::cases())->map(fn (Provider $provider): array => [
                'key' => $provider->value,
                'name' => $provider->label(),
                'services' => $provider->services(),
                'connectable' => $provider->isConnectable(),
                'accounts' => $accounts->get($provider->value, collect())->map(fn (ConnectedAccount $account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'email' => $account->email,
                    'workspace' => $account->metadata['team_name'] ?? $account->metadata['workspace_name'] ?? $account->metadata['site_name'] ?? null,
                    'has_rules' => $provider === Provider::Atlassian ? filled($account->rules) : null,
                    'avatar_url' => $account->avatar_url,
                    'status' => $account->status->value,
                    'missing_scopes' => count($account->missingScopes()),
                    'capabilities' => array_map(fn (Capability $capability): array => [
                        'key' => $capability->value,
                        'label' => $capability->label(),
                        'granted' => $account->grants($capability),
                        'enabled' => $account->allows($capability),
                    ], $provider->capabilities()),
                    'connected_at' => $account->created_at->toIso8601String(),
                ])->values()->all(),
            ])->all(),
        ]);
    }

    /**
     * Disconnect an account and revoke its access at the provider.
     */
    public function destroy(Request $request, AccessTokens $tokens, int $account): RedirectResponse
    {
        $account = $request->user()->connectedAccounts()->findOrFail($account);

        try {
            $tokens->revoke($account);
        } catch (Throwable $exception) {
            report($exception);
        }

        $account->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Cuenta {$account->email} desconectada."]);

        return to_route('settings.connections.index');
    }
}
