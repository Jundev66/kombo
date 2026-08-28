<?php

declare(strict_types=1);

namespace Platform\Modules\Middleware;

use Closure;
use Illuminate\Http\Request;
use Platform\Capabilities\CurrentCapabilities;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Exige que el negocio tenga encendido este módulo.
 *
 * Responde **404, no 403**, y la diferencia es deliberada:
 *
 * - Que un módulo no exista para un negocio es información sobre **su
 *   contrato**, no sobre sus permisos. Para una cocina oculta que sólo vende
 *   por el portal, la caja sencillamente no existe: no hay nada que pedirle al
 *   dueño, ni nada que explicarle.
 * - Un 403 diría «esto existe pero no puedes», que es exactamente lo que no
 *   queremos: invita a insistir y filtra qué funcionalidades hay.
 *
 * (Al revés que `permission:`, que sí responde 403 — ahí la decisión es del
 * propio dueño y hay que decírselo claro.)
 */
final class RequireModule
{
    public function __construct(private readonly CurrentCapabilities $capabilities) {}

    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        foreach ($modules as $module) {
            if (! $this->capabilities->get()->hasModule($module)) {
                throw new NotFoundHttpException;
            }
        }

        return $next($request);
    }
}
