<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Permite continuar únicamente a usuarios activos.
     *
     * Si un administrador bloquea una cuenta que ya tenía
     * una sesión abierta, la sesión se cerrará automáticamente.
     */
    public function handle(
        Request $request,
        Closure $next
    ): Response|RedirectResponse {
        $user = $request->user();

        /*
         * Este middleware debe utilizarse después de "auth",
         * pero también protegemos el caso de una sesión inexistente.
         */
        if ($user === null) {
            return redirect()->route('login');
        }

        if (! $user->canAccessSystem()) {
            /*
             * Cierra completamente la sesión para impedir
             * que el usuario bloqueado continúe navegando.
             */
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => 'Tu cuenta está bloqueada. Comunícate con un administrador.',
                ]);
        }

        return $next($request);
    }
}