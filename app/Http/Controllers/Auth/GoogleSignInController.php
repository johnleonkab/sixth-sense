<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as OAuthUser;
use Throwable;

/**
 * Sign in with a Google account. This only identifies the person. Connecting
 * Gmail or Calendar stays a separate step, with its own permissions.
 */
class GoogleSignInController extends Controller
{
    public function redirect(): RedirectResponse
    {
        $this->configured();

        return Socialite::driver('google')
            ->setScopes(['openid', 'profile', 'email'])
            ->with(['prompt' => 'select_account'])
            ->redirectUrl(route('auth.google.callback'))
            ->redirect();
    }

    public function callback(PostHogService $posthog): RedirectResponse
    {
        $this->configured();

        if (request()->filled('error')) {
            return $this->fail('Has cancelado el acceso de Google.');
        }

        try {
            /** @var OAuthUser $google */
            $google = Socialite::driver('google')
                ->redirectUrl(route('auth.google.callback'))
                ->user();
        } catch (InvalidStateException) {
            return $this->fail('La sesión con Google ha caducado. Inténtalo otra vez.');
        } catch (Throwable $exception) {
            report($exception);

            return $this->fail('No he podido entrar con Google. Inténtalo otra vez.');
        }

        $email = Str::lower(trim((string) $google->getEmail()));
        $googleId = (string) $google->getId();

        if ($email === '' || $googleId === '' || ! $this->emailIsVerified($google)) {
            return $this->fail('Google no ha confirmado este email.');
        }

        $user = User::query()->where('google_id', $googleId)->first();

        if ($user === null) {
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

            if ($user !== null && filled($user->google_id) && $user->google_id !== $googleId) {
                return $this->fail('Esta cuenta ya está vinculada a otro Google.');
            }

            if ($user === null) {
                if (! config('auth.registration')) {
                    return $this->fail('No hay una cuenta con este email.');
                }

                $user = User::query()->create([
                    'name' => $google->getName() ?: Str::before($email, '@'),
                    'email' => $email,
                    'google_id' => $googleId,
                    'password' => Str::password(40),
                ]);
                $user->email_verified_at = now();
                $user->save();
            } else {
                $user->google_id = $googleId;
                $user->email_verified_at ??= now();
                $user->save();
            }
        }

        Auth::login($user);
        request()->session()->regenerate();

        $distinctId = (string) $user->getAuthIdentifier();
        $posthog->identify($distinctId, [
            'email' => $user->email,
            'name' => $user->name,
        ]);
        $posthog->capture($distinctId, 'user_logged_in', [
            'login_method' => 'google',
        ]);

        return redirect()->intended(route('home', absolute: false));
    }

    private function configured(): void
    {
        abort_unless(
            filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
            404,
        );
    }

    private function emailIsVerified(OAuthUser $google): bool
    {
        $verified = $google->user['email_verified'] ?? false;

        return $verified === true || $verified === 1 || $verified === 'true';
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['google' => $message]);
    }
}
