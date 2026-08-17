<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Permite continuar únicamente a administradores activos.
     */
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $user = $request->user();

        /*
         * Si no existe una sesión autenticada,
         * Laravel devuelve acceso no autorizado.
         */
        abort_if(
            $user === null,
            401,
            'Debes iniciar sesión para continuar.'
        );

        /*
         * La comprobación de usuario activo se repetirá
         * como defensa adicional.
         */
        abort_unless(
            $user->canAccessSystem(),
            403,
            'Tu cuenta se encuentra bloqueada.'
        );

        /*
         * Un técnico no puede entrar en módulos
         * exclusivos de administración.
         */
        abort_unless(
            $user->isAdmin(),
            403,
            'No tienes permisos administrativos.'
        );

        return $next($request);
    }
}