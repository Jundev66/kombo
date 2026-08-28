<?php

declare(strict_types=1);

namespace Platform\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Platform\Capabilities\CurrentCapabilities;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Exige TODOS los permisos indicados: `permission:catalog.manage`.
 *
 * Responde 403 con un mensaje que se puede leer. Al revés que `module:`, que
 * responde 404: aquí la funcionalidad existe y quien decide quién la usa es el
 * dueño del negocio, así que se dice claro en vez de fingir que no está.
 *
 * La comprobación vive en un middleware y no dentro de cada controlador para
 * que ningún módulo nuevo pueda olvidársela.
 */
final class RequirePermission
{
    public function __construct(private readonly CurrentCapabilities $capabilities) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        if ($request->user() === null) {
            throw new UnauthorizedHttpException('Session', 'Inicia sesión para continuar.');
        }

        // Un token de DISPOSITIVO no ejecuta acciones con permiso: sólo sirve
        // para pedir la lista de nombres y validar un PIN. Vive en una tablet
        // del local que se presta y se pierde.
        //
        // `tokenCan()` devuelve true para las sesiones por cookie, así que el
        // panel y el portal no se ven afectados.
        if (! $request->user()->tokenCan('station')) {
            throw new AccessDeniedHttpException(
                'Esta pantalla todavía no tiene a nadie dentro. Entra con tu PIN.'
            );
        }

        foreach ($permissions as $permission) {
            if (! $this->capabilities->get()->can($permission)) {
                throw new AccessDeniedHttpException(
                    'Tu usuario no tiene permiso para esto. Pídeselo al dueño del negocio.'
                );
            }
        }

        return $next($request);
    }
}
