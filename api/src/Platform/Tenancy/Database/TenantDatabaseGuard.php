<?php

declare(strict_types=1);

namespace Platform\Tenancy\Database;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\TransactionBeginning;

/**
 * Le dice a PostgreSQL de qué negocio es esta conexión.
 *
 * Aquí es donde el aislamiento deja de ser una convención del código y pasa a
 * ser una garantía de la base de datos: la política `tenant_isolation` lee
 * este parámetro y filtra sola, en toda consulta, venga de Eloquent, de una
 * consulta cruda o de un informe.
 */
final class TenantDatabaseGuard
{
    private ?string $tenantId = null;

    public function __construct(private readonly DatabaseManager $db) {}

    public function apply(string $tenantId): void
    {
        $this->tenantId = $tenantId;
        $this->write($tenantId, local: false);
    }

    public function clear(): void
    {
        $this->tenantId = null;

        // Cadena vacía y no NULL: el `nullif(..., '')` de la política la
        // convierte en null, y `tenant_id = null` no es true. Cero filas.
        $this->write('', local: false);
    }

    public function current(): ?string
    {
        return $this->tenantId;
    }

    /**
     * Vuelve a fijar el negocio al abrir cada transacción, con alcance LOCAL.
     *
     * Sin esto hay un agujero que sólo aparece bajo concurrencia y es
     * dificilísimo de reproducir: una conexión devuelta al pool —pgbouncer, un
     * worker de cola, un proceso persistente— conserva el parámetro del
     * negocio ANTERIOR. La siguiente petición que la tome antes de que el
     * middleware la reconfigure vería datos ajenos.
     *
     * Sólo en la transacción más externa: las anidadas son puntos de guardado
     * y volver a fijarlo ahí no aporta nada.
     */
    public function onTransactionBeginning(TransactionBeginning $event): void
    {
        if ($this->tenantId === null) {
            return;
        }

        if ($event->connection->transactionLevel() > 1) {
            return;
        }

        $event->connection->statement(
            'select set_config(?, ?, true)',
            [TenantSchema::GUC, $this->tenantId],
        );
    }

    /**
     * `set_config()` y no `SET`, porque `SET` no admite parámetros ligados y
     * habría que interpolar el identificador en el SQL a mano.
     */
    private function write(string $value, bool $local): void
    {
        $this->db->connection()->statement(
            'select set_config(?, ?, ?)',
            [TenantSchema::GUC, $value, $local],
        );
    }
}
