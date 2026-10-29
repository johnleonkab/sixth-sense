<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Show the sign-up screen, when registration is switched on.
     */
    public function create(): Response
    {
        abort_unless(config('auth.registration'), 404);

        return Inertia::render('auth/register');
    }

    /**
     * Create the account and start a session with it.
     */
    public function store(RegisterRequest $request, PostHogService $posthog): RedirectResponse
    {
        $user = User::create($request->safe()->only('name', 'email', 'password'));

        event(new Registered($user));

        Auth::login($user);

        $distinctId = (string) $user->getAuthIdentifier();

        $posthog->identify($distinctId, [
            'email' => $user->email,
            'name' => $user->name,
        ]);
        $posthog->capture($distinctId, 'user_registered', [
            'signup_method' => 'registration_form',
        ]);
        $request->session()->regenerate();

        return redirect()->route('home');
    }
}
