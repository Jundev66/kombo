<?php

declare(strict_types=1);

namespace Platform\Subscription\Http;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Platform\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended tenant signs in, reads and exports. It does not write.
 *
 * Both halves matter: their orders, customers and menu are still theirs and
 * they must be able to get them out even owing us three months. What is cut off
 * is carrying on for free.
 *
 * One middleware, not an `if` per controller — that is exactly where the
 * previous project failed, with the check wired into 2 of some 20 controllers.
 */
final class EnsureTenantCanWrite
{
    /** Methods that change nothing, and are therefore never blocked. */
    private const LECTURA = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * What is let through even while suspended.
     *
     * Signing out has to work always, and so does signing in: without it they
     * could not read or export, which is precisely what they are allowed.
     */
    private const SIEMPRE = ['auth.login', 'auth.logout', 'auth.device', 'auth.pin'];

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), self::LECTURA, true)) {
            return $next($request);
        }

        if (! $this->context->has()) {
            // With no tenant there is no subscription to check: platform administration,
            // or a webhook that resolves its own inside.
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if ($routeName !== null && in_array($routeName, self::SIEMPRE, true)) {
            return $next($request);
        }

        $tenant = $this->context->current();

        if ($tenant->status->allowsWrites()) {
            return $next($request);
        }

        /*
         * 402, not 403. A 403 says "you lack permission", which is untrue and
         * sends the owner to check their team's roles. 402 says what is
         * actually happening, and the message carries the reason.
         */
        return new JsonResponse([
            'message' => $tenant->status->value === 'suspended'
                ? 'La cuenta está suspendida por falta de pago. Puedes consultar y exportar tus datos; para volver a operar, ponte al día.'
                : 'La cuenta está cerrada.',
            'tenantStatus' => $tenant->status->value,
        ], 402);
    }
}
