<?php

declare(strict_types=1);

namespace Platform\Tenancy;

use Illuminate\Database\DatabaseManager;
use Platform\Capabilities\CurrentCapabilities;
use Platform\Tenancy\Database\TenantDatabaseGuard;
use Platform\Tenancy\Exceptions\TenantNotFound;

/**
 * Entering a tenant OUTSIDE an HTTP request — a scheduled task, a queued job,
 * a channel webhook.
 *
 * It exists because entering is three things, not one: the PostgreSQL
 * parameter so RLS filters, `TenantContext` so Eloquent's global scope does not
 * apply `1 = 0`, and forgetting the previous tenant's memoised capabilities.
 * With only the first, raw SQL works and Eloquent returns zero rows.
 */
final class TenantSession
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantDatabaseGuard $guard,
        private readonly CurrentCapabilities $capabilities,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @throws TenantNotFound
     */
    public function enter(string $tenantId): Tenant
    {
        $tenant = $this->byId($tenantId);

        $this->context->set($tenant);
        $this->guard->apply($tenant->id);
        $this->capabilities->reset();

        return $tenant;
    }

    /**
     * Runs the work inside the tenant and leaves things as they were.
     *
     * Restore, not clear: a listener entering a tenant mid-request — sync queue
     * or an inline event — used to clear the context on the way out and leave
     * the request with no tenant, surfacing as a 404 on the next line with no
     * apparent connection to the cause.
     *
     * If there was nothing before, it exits clean: a pooled connection with a
     * tenant still set is exactly what RLS is there to prevent.
     *
     * @template T
     *
     * @param  callable(Tenant): T  $work
     * @return T
     */
    public function within(string $tenantId, callable $work): mixed
    {
        $previous = $this->context->has() ? $this->context->current() : null;

        $tenant = $this->enter($tenantId);

        try {
            return $work($tenant);
        } finally {
            if ($previous === null) {
                $this->leave();
            } else {
                $this->context->set($previous);
                $this->guard->apply($previous->id);
                $this->capabilities->reset();
            }
        }
    }

    public function leave(): void
    {
        $this->context->forget();
        $this->guard->clear();
        $this->capabilities->reset();
    }

    /**
     * @throws TenantNotFound
     */
    private function byId(string $tenantId): Tenant
    {
        // Deliberately uncached: entering by id happens from tasks and queues,
        // which are rare next to web requests. Caching here would add a second key
        // to invalidate, and a badly invalidated tenant cache is expensive.
        $row = $this->db->table('tenants')
            ->where('id', $tenantId)
            ->whereNull('deleted_at')
            ->first();

        if ($row === null) {
            throw new TenantNotFound($tenantId);
        }

        return Tenant::fromRow($row);
    }
}
