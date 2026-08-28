<?php

declare(strict_types=1);

namespace Platform\Subscription\Http;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sólo pasa un administrador de plataforma.
 *
 * Se comprueba el guard `platform` explícitamente, y no `auth` a secas: con el
 * guard por defecto, la sesión de un empleado de un negocio cualquiera abriría
 * la facturación de todos los clientes. Es el tipo de fallo que no se nota
 * hasta que alguien lo prueba.
 */
final class RequirePlatformUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('platform')->user();

        if ($user === null || $user->is_active !== true) {
            return new JsonResponse(['message' => 'Aquí no entras.'], 401);
        }

        return $next($request);
    }
}
