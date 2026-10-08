<?php

namespace App\Http\Controllers\Settings;

use App\Alerts\AlertSource;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    /**
     * Every alert source, whether it is on and which accounts it watches.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/alerts', [
            'sources' => array_map(fn (AlertSource $source): array => [
                'key' => $source->value,
                'name' => $source->label(),
                'description' => $source->description(),
                'enabled' => $user->wantsAlertsFrom($source),
                'capability' => $source->capability()->label(),
                'accounts' => $user->connectedAccounts()->ofProvider($source->capability()->provider())->oldest()->orderBy('id')->get()
                    ->map(fn (ConnectedAccount $account): array => [
                        'id' => $account->id,
                        'email' => $account->email,
                        'watched' => $account->can($source->capability()),
                    ])->all(),
            ], AlertSource::cases()),
        ]);
    }

    /**
     * Switch alerts from a source on or off.
     */
    public function update(Request $request, AlertSource $source): RedirectResponse
    {
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        $request->user()->alertSettings()->updateOrCreate(['source' => $source], ['enabled' => $enabled]);

        return back();
    }
}
