<?php

namespace App\Http\Controllers\Settings;

use App\Connections\Capability;
use App\Http\Controllers\Controller;
use App\Jobs\SyncWingmanMeetings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WingmanController extends Controller
{
    /**
     * Whether Wingman follows the person's calls.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/wingman', [
            'enabled' => $request->user()->wingman_enabled,
            'calendars' => $request->user()->accountsWith(Capability::CalendarRead)->pluck('email')->values()->all(),
            'demo' => (bool) config('connectors.demo'),
        ]);
    }

    /**
     * Turn Wingman on or off. Turned on, today's calls are looked up at once.
     */
    public function update(Request $request): RedirectResponse
    {
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $request->user()->update(['wingman_enabled' => $enabled]);

        if ($enabled) {
            SyncWingmanMeetings::dispatch($request->user());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $enabled ? 'Wingman activado: seguirá tus llamadas de Meet.' : 'Wingman desactivado.']);

        return back();
    }
}
