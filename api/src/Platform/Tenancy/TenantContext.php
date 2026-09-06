<?php

declare(strict_types=1);

namespace Platform\Tenancy;

use Platform\Tenancy\Exceptions\TenantNotResolved;

/**
 * Which tenant this request belongs to.
 *
 * Half the story: TenantDatabaseGuard writes the same id onto the PostgreSQL
 * connection for RLS to read. This class is convenience for the code; the
 * guarantee is over there.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    /**
     * @throws TenantNotResolved when there is no tenant — on purpose.
     */
    public function current(): Tenant
    {
        return $this->tenant ?? throw new TenantNotResolved;
    }

    public function id(): string
    {
        return $this->current()->id;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * For tests and for queued jobs that switch tenant. A normal request has no
     * reason to call it.
     */
    public function forget(): void
    {
        $this->tenant = null;
    }
}
