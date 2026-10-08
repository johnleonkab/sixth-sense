<?php

namespace App\Http\Controllers\Settings;

use App\Connections\Capability;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountCapabilityController extends Controller
{
    /**
     * Switch a capability on or off for one of the user's accounts.
     */
    public function update(Request $request, int $account, Capability $capability): RedirectResponse
    {
        $account = $request->user()->connectedAccounts()->findOrFail($account);

        abort_unless($capability->provider() === $account->provider, 404);

        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        if ($enabled && ! $account->grants($capability)) {
            throw ValidationException::withMessages([
                'enabled' => "{$account->provider->label()} no ha dado permiso para «{$capability->label()}». Vuelve a conectar la cuenta.",
            ]);
        }

        $account->toggleCapability($capability, (bool) $enabled);

        return back();
    }
}
