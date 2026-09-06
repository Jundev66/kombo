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
 * Requires ALL of the listed permissions: `permission:catalog.manage`.
 *
 * Answers 403, unlike `module:` which answers 404: here the feature exists and
 * the owner decides who uses it, so it is said plainly. It lives in middleware
 * so a new module cannot forget it.
 */
final class RequirePermission
{
    public function __construct(private readonly CurrentCapabilities $capabilities) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        if ($request->user() === null) {
            throw new UnauthorizedHttpException('Session', 'Inicia sesión para continuar.');
        }

        // A DEVICE token never executes a permissioned action: it only lists names
        // and validates a PIN. `tokenCan()` is true for cookie sessions, so the
        // dashboard and the portal are unaffected.
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
