<?php

declare(strict_types=1);

namespace Platform\Tenancy\Database;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a tenant table is created, so that getting it wrong is impossible.
 *
 * Isolation rests on four things in EVERY table: a `tenant_id` column, RLS
 * enabled AND forced, a `tenant_isolation` policy, and indexes that start with
 * `tenant_id` with COMPOSITE foreign keys. Remembering all four in every
 * migration, forever, is not a plan — `create()` does them. And if someone
 * hand-rolls a table, `SchemaGuardTest` walks the PostgreSQL catalog and fails.
 */
final class TenantSchema
{
    /**
     * The PostgreSQL session parameter holding the current tenant. Written by
     * TenantDatabaseGuard, read by the RLS policy.
     */
    public const GUC = 'app.tenant_id';

    /**
     * Tables with no `tenant_id`, and therefore no RLS.
     *
     * Two kinds: Laravel's infrastructure, and the platform's own — queried
     * BEFORE we know which tenant we are talking about.
     *
     * Explicit on purpose. Adding to this list should sting a little: it
     * declares that the table sits outside isolation.
     *
     * @var list<string>
     */
    public const PLATFORM_TABLES = [
        // Laravel infrastructure
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'password_reset_tokens',

        // Platform: queried with no tenant in context
        'plans',
        'plan_modules',
        'tenants',
        'tenant_domains',
        'subscriptions',
        'subscription_payments',
        'platform_users',
        'platform_audit_log',

        /*
         * The webhooks' phone book. A message from Meta carries no subdomain,
         * so we have to know whose it is BEFORE querying anything of theirs —
         * which is exactly what RLS prevents without context. No credentials
         * and no messages live here, only "this number belongs to this tenant".
         */
        'channel_routes',
    ];

    /**
     * Unique indexes that deliberately do NOT start with `tenant_id`, with the
     * reason. The reason is what gets read when someone asks why, in two years.
     *
     * @var array<string, string>
     */
    public const GLOBAL_UNIQUE_INDEXES = [
        'personal_access_tokens_token_unique' => 'Es el hash de un token de acceso. Un token tiene que resolver a un único usuario ANTES de saber de qué negocio es —esa es justo la información que trae—, así que la unicidad debe ser global.',
    ];

    /**
     * Creates a tenant table with everything mandatory already in place: uuid
     * `id`, `tenant_id`, timestamps with zone, the `(tenant_id, id)` unique
     * index, and RLS enabled, forced and with its policy.
     */
    public static function create(string $table, Closure $definition): void
    {
        Schema::create($table, function (Blueprint $blueprint) use ($definition): void {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('tenant_id');

            $definition($blueprint);

            $blueprint->timestampsTz();

            // This unique index is what makes the composite FKs below possible.
            $blueprint->unique(['tenant_id', 'id'], "uq_{$blueprint->getTable()}_tenant_id");
        });

        self::enableRowLevelSecurity($table);
    }

    /**
     * A foreign key to another tenant table. ALWAYS composite:
     * `(tenant_id, column) → (tenant_id, id)` rather than `column → id`.
     *
     * That is what stops one tenant's order referencing another's product at
     * the database level. With a simple FK it is a perfectly valid row, and the
     * mistake surfaces months later when a report does not add up.
     */
    public static function references(
        Blueprint $table,
        string $column,
        string $referencedTable,
        bool $nullable = false,
        string $onDelete = 'restrict',
    ): void {
        $definition = $table->uuid($column);

        if ($nullable) {
            $definition->nullable();
        }

        $table->foreign(['tenant_id', $column], "fk_{$table->getTable()}_{$column}")
            ->references(['tenant_id', 'id'])
            ->on($referencedTable)
            ->onDelete($onDelete);
    }

    /**
     * An index starting with `tenant_id`.
     *
     * Every query carries `where tenant_id = ?`, so an index that starts
     * elsewhere makes PostgreSQL walk other tenants' rows to discard them.
     */
    public static function index(Blueprint $table, array $columns, ?string $name = null): void
    {
        $table->index(['tenant_id', ...$columns], $name);
    }

    /**
     * Unique per tenant: two tenants can share a product code.
     */
    public static function uniquePerTenant(Blueprint $table, array $columns, ?string $name = null): void
    {
        $table->unique(['tenant_id', ...$columns], $name);
    }

    /**
     * The isolation policy, copied verbatim. Every part is there for a
     * different reason.
     */
    public static function enableRowLevelSecurity(string $table): void
    {
        DB::statement("alter table {$table} enable row level security");

        // FORCE is essential: without it the table's OWNER silently bypasses the
        // policy — and the owner is who runs migrations and seeders.
        DB::statement("alter table {$table} force row level security");

        // USING protects reads. WITH CHECK protects writes: it stops INSERTing a
        // row labelled as another tenant's, a separate hole USING alone leaves.
        // `nullif(current_setting(..., true), '')` covers both no-tenant cases —
        // unset (null) and set to empty string, which is how a cleaned connection
        // is left. Both compare to null, and null is not true: ZERO rows.
        // The failure mode is to DENY: if something breaks you see nothing, never
        // more than you should.
        DB::statement(<<<SQL
            create policy tenant_isolation on {$table}
                for all
                using (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
                with check (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
        SQL);
    }
}
