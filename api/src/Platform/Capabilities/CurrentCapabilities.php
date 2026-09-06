<?php

declare(strict_types=1);

namespace Platform\Capabilities;

use Illuminate\Contracts\Auth\Factory as Auth;
use Platform\Tenancy\TenantContext;

/**
 * This request's capabilities, computed once.
 *
 * Memoised per request: between the module middleware, the permission
 * middleware and the use case itself, this is asked three or four times.
 */
final class CurrentCapabilities
{
    private ?TenantCapabilities $resolved = null;

    public function __construct(
        private readonly CapabilityResolver $resolver,
        private readonly TenantContext $context,
        private readonly Auth $auth,
    ) {}

    public function get(): TenantCapabilities
    {
        return $this->resolved ??= $this->compute();
    }

    /**
     * Discards and recomputes. Needed when something changed WITHIN the same
     * request — enabling a module and answering with the new menu already.
     */
    public function refresh(): TenantCapabilities
    {
        $this->resolved = null;

        return $this->get();
    }

    /** Called by ResolveTenant: the memo is good for one request, no more. */
    public function reset(): void
    {
        $this->resolved = null;
    }

    /**
     * @return list<string>
     */
    public function permissionsOfCurrentUser(): array
    {
        $user = $this->auth->guard()->user();

        // No session means zero permissions, and that is not an error: `/me`
        // answers without one so the login screen can show the tenant's name.
        if ($user === null) {
            return [];
        }

        return method_exists($user, 'permissionNames') ? $user->permissionNames() : [];
    }

    private function compute(): TenantCapabilities
    {
        $tenant = $this->context->current();

        return $this->resolver->resolve(
            tenantId: $tenant->id,
            planCode: $tenant->planCode,
            userPermissions: $this->permissionsOfCurrentUser(),
        );
    }
}
