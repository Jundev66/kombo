<?php

declare(strict_types=1);

namespace Platform\Tenancy\Database;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\TransactionBeginning;

/**
 * Tells PostgreSQL which tenant this connection belongs to.
 *
 * This is where isolation stops being a code convention and becomes a database
 * guarantee: the `tenant_isolation` policy reads this parameter and filters on
 * its own, for every query — Eloquent, raw SQL or a report.
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

        // Empty string rather than NULL: the policy's `nullif(..., '')` turns it
        // into null, and `tenant_id = null` is not true. Zero rows.
        $this->write('', local: false);
    }

    public function current(): ?string
    {
        return $this->tenantId;
    }

    /**
     * Re-pins the tenant when each transaction opens, scoped LOCAL.
     *
     * Without it there is a hole that only shows under concurrency: a
     * connection returned to the pool keeps the PREVIOUS tenant's parameter,
     * and the next request to take it before the middleware reconfigures it
     * would see somebody else's data.
     *
     * Outermost transaction only; nested ones are savepoints.
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
     * `set_config()` rather than `SET`, because `SET` takes no bound
     * parameters and the id would have to be interpolated into the SQL by hand.
     */
    private function write(string $value, bool $local): void
    {
        $this->db->connection()->statement(
            'select set_config(?, ?, ?)',
            [TenantSchema::GUC, $value, $local],
        );
    }
}
