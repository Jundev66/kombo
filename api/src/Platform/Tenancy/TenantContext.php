<?php

declare(strict_types=1);

namespace Platform\Tenancy;

use Platform\Tenancy\Exceptions\TenantNotResolved;

/**
 * Quién es el negocio de esta petición.
 *
 * Singleton explícito, registrado a mano en PlatformServiceProvider: un ciclo
 * de vida accidental no es un ciclo de vida. Si esto se resolviera dos veces
 * por petición el fallo sería intermitente y carísimo de encontrar.
 *
 * Es sólo la mitad de la historia. La otra mitad la lleva TenantDatabaseGuard,
 * que escribe el mismo identificador en la conexión de PostgreSQL para que RLS
 * lo lea. Esta clase es comodidad para el código; la garantía está allá.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    /**
     * @throws TenantNotResolved cuando no hay negocio — a propósito.
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
     * Sólo para pruebas y para trabajos en cola que cambian de negocio. En una
     * petición normal no hay razón para llamarlo.
     */
    public function forget(): void
    {
        $this->tenant = null;
    }
}
