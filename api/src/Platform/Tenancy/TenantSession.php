<?php

declare(strict_types=1);

namespace Platform\Tenancy;

use Illuminate\Database\DatabaseManager;
use Platform\Capabilities\CurrentCapabilities;
use Platform\Tenancy\Database\TenantDatabaseGuard;
use Platform\Tenancy\Exceptions\TenantNotFound;

/**
 * Entrar en un negocio **fuera de una petición HTTP**.
 *
 * Hay tres sitios donde hace falta y ninguno tiene subdominio del que deducir
 * nada: una tarea programada, un trabajo de la cola, y el webhook de un canal
 * —que llega a una dirección común con el identificador dentro del cuerpo—.
 *
 * Existe porque entrar son **tres cosas, no una**, y hacer sólo la primera es
 * un fallo que engaña:
 *
 *   1. El parámetro de PostgreSQL, para que RLS filtre.
 *   2. `TenantContext`, para que Eloquent sepa qué `tenant_id` poner al crear
 *      —y, sobre todo, para que su ámbito global no aplique `1 = 0`—.
 *   3. Olvidar las capacidades memorizadas, que son del negocio anterior.
 *
 * Con sólo la primera, las consultas por SQL crudo funcionan y las de Eloquent
 * devuelven cero filas. Cuesta un rato entender por qué.
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
     * Hace el trabajo dentro del negocio y **deja las cosas como estaban**.
     *
     * Restaurar, no limpiar, y la diferencia es un fallo real que costó
     * encontrar: un oyente que entra a un negocio en mitad de una petición
     * —porque la cola corre en modo síncrono, o porque es un evento inline—
     * limpiaba el contexto al salir y dejaba a la petición SIN negocio a media
     * ejecución. El síntoma era un 404 en la línea siguiente, que no tiene
     * ninguna relación aparente con la causa.
     *
     * Si no había nada antes, se sale limpio: una conexión devuelta al pool con
     * un negocio puesto es justo lo que RLS viene a evitar.
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
        // Sin caché a propósito: por identificador se entra desde tareas y
        // colas, que son poco frecuentes comparadas con las peticiones web.
        // Cachear aquí añadiría una segunda clave que invalidar, y la caché mal
        // invalidada de un negocio es de los fallos más caros que hay.
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
