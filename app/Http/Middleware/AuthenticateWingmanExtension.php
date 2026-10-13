<?php

namespace App\Http\Middleware;

use App\Wingman\ExtensionTokens;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the "Wingman para Meet" extension in with its personal token, for
 * people who have Wingman turned on.
 */
class AuthenticateWingmanExtension
{
    public function __construct(private ExtensionTokens $tokens) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->tokens->userFor($request->bearerToken());

        if ($user === null) {
            return response()->json(['message' => 'Token no válido: vuelve a conectar la extensión desde Configuración → Wingman.'], 401);
        }

        if (! $user->wingman_enabled) {
            return response()->json(['message' => 'Wingman está desactivado en Configuración → Wingman.'], 403);
        }

        Auth::setUser($user);

        return $next($request);
    }
}
