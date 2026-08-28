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
 * Basta con UNO de los permisos: `permission.any:orders.void,orders.void_request`.
 *
 * Es la puerta del flujo de autorización por PIN. Pasar por aquí significa
 * poder **iniciar** la acción, no ejecutarla: quien sólo tiene el permiso
 * `_request` llega al caso de uso, y allí `ActionAuthorizer` decide si hace
 * falta el PIN de un supervisor.
 */
final class RequireAnyPermission
{
    public function __construct(private readonly CurrentCapabilities $capabilities) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        if ($request->user() === null) {
            throw new UnauthorizedHttpException('Session', 'Inicia sesión para continuar.');
        }

        if (! $request->user()->tokenCan('station')) {
            throw new AccessDeniedHttpException(
                'Esta pantalla todavía no tiene a nadie dentro. Entra con tu PIN.'
            );
        }

        foreach ($permissions as $permission) {
            if ($this->capabilities->get()->can($permission)) {
                return $next($request);
            }
        }

        throw new AccessDeniedHttpException(
            'Tu usuario no tiene permiso para esto. Pídeselo al dueño del negocio.'
        );
    }
}
