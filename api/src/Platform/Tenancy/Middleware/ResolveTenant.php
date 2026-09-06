<?php

declare(strict_types=1);

namespace Platform\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Platform\Capabilities\CurrentCapabilities;
use Platform\Tenancy\Database\TenantDatabaseGuard;
use Platform\Tenancy\TenantContext;
use Platform\Tenancy\TenantResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * The first thing on every request: work out which tenant it is for.
 *
 * Prepended to the `web` and `api` groups, and before authentication, so the
 * user is looked up INSIDE the resolved tenant and the same email in two
 * tenants signs into the right one.
 *
 * A host with no tenant (the root domain, or `admin.`) carries on without
 * context: that is where sign-up and platform administration live.
 */
final class ResolveTenant
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
        private readonly TenantDatabaseGuard $guard,
        private readonly CurrentCapabilities $capabilities,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $this->resolver->slugFromHost($request->getHost());

        if ($slug === null) {
            return $next($request);
        }

        // Throws TenantNotFound (404) when there is none. It never falls back to a
        // default tenant: serving another tenant's data would be far worse than an
        // error page.
        $tenant = $this->resolver->bySlug($slug);

        if (! $tenant->status->allowsAccess()) {
            return response()->json([
                'message' => 'Esta cuenta está cerrada. Escríbenos si crees que es un error.',
            ], Response::HTTP_FORBIDDEN);
        }

        $this->context->set($tenant);
        $this->guard->apply($tenant->id);

        // Auth guards memoise the user and capabilities memoise their result. Both
        // are good for ONE request: without this, a persistent process (Octane, a
        // worker) could serve the next request with the previous user, from
        // another tenant.
        Auth::forgetGuards();
        $this->capabilities->reset();

        return $next($request);
    }

    /**
     * Cleans the connection before it returns to the pool.
     *
     * With the TransactionBeginning listener, this is what stops a reused
     * connection carrying the previous request's tenant.
     */
    public function terminate(Request $request, Response $response): void
    {
        if ($this->guard->current() !== null) {
            $this->guard->clear();
        }
    }
}
