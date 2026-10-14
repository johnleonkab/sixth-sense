<?php

namespace App\Http\Controllers\Settings;

use App\Connections\Capability;
use App\Http\Controllers\Controller;
use App\Jobs\SyncWingmanMeetings;
use App\Wingman\ExtensionTokens;
use App\Wingman\WingmanSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            'settings' => WingmanSettings::for($request->user())->toArray(),
            'extension' => [
                'connected' => $request->user()->wingman_token_hash !== null,
                'used_at' => $request->user()->wingman_token_used_at?->toIso8601String(),
                'server' => rtrim((string) config('app.url'), '/'),
            ],
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

    /**
     * A new token for the browser extension, shown once. The previous one stops working.
     */
    public function issueToken(Request $request, ExtensionTokens $tokens): RedirectResponse
    {
        Inertia::flash('wingman_token', $tokens->issue($request->user()));

        return back();
    }

    /**
     * Disconnect the browser extension.
     */
    public function revokeToken(Request $request, ExtensionTokens $tokens): RedirectResponse
    {
        $tokens->revoke($request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Extensión desconectada: su token ya no sirve.']);

        return back();
    }

    /**
     * Which calendars Wingman follows, which meetings it never listens to, and whether it keeps what was said.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $calendars = $request->user()->accountsWith(Capability::CalendarRead)->pluck('email')->all();

        $data = $request->validate([
            'calendars' => ['present', 'nullable', 'array'],
            'calendars.*' => ['string', Rule::in($calendars)],
            'never_listen' => ['present', 'array', 'max:'.WingmanSettings::MAX_RULES],
            'never_listen.*' => ['nullable', 'string', 'max:60'],
            'keep_transcript' => ['required', 'boolean'],
        ]);

        $settings = new WingmanSettings(
            calendars: $data['calendars'] === null ? null : array_values(array_unique($data['calendars'])),
            neverListen: array_values(array_unique(array_filter(array_map(fn (?string $rule): string => trim((string) $rule), $data['never_listen'])))),
            keepTranscript: (bool) $data['keep_transcript'],
        );

        $request->user()->update(['wingman_settings' => $settings->toArray()]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ajustes de Wingman guardados.']);

        return back();
    }
}
