<?php

declare(strict_types=1);

/*
 * The schema, checked against PostgreSQL's real catalog.
 *
 * The other architecture tests read files. These ask the database how it
 * actually ended up, which is the only thing that matters: a migration can call
 * TenantSchema::create() and somebody can still add an index by hand after.
 *
 * When one fails, fix the migration to use TenantSchema — do not add the table
 * to the exception list.
 */

use Illuminate\Support\Facades\DB;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Every table that is neither platform nor infrastructure.
 *
 * @return list<string>
 */
function businessTables(): array
{
    $tables = DB::select("select tablename from pg_tables where schemaname = 'public'");

    return array_values(array_filter(
        array_map(static fn (object $row): string => $row->tablename, $tables),
        static fn (string $table): bool => ! in_array($table, TenantSchema::PLATFORM_TABLES, true),
    ));
}

it('every tenant table has a tenant_id', function (): void {
    $offenders = [];

    foreach (businessTables() as $table) {
        $has = DB::select(
            'select 1 from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
            ['public', $table, 'tenant_id'],
        );

        if ($has === []) {
            $offenders[] = $table;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'Estas tablas no tienen tenant_id: '.implode(', ', $offenders),
        '',
        'O se crean con TenantSchema::create(), o se declaran explícitamente',
        'en TenantSchema::PLATFORM_TABLES con la razón por la que quedan fuera',
        'del aislamiento. No hay tercera opción.',
    ]));
});

it('every tenant table has RLS enabled AND forced', function (): void {
    $offenders = [];

    foreach (businessTables() as $table) {
        $row = DB::selectOne(
            "select relrowsecurity as enabled, relforcerowsecurity as forced
             from pg_class where relname = ? and relnamespace = 'public'::regnamespace",
            [$table],
        );

        if ($row === null || ! $row->enabled) {
            $offenders[] = "{$table}: RLS sin activar";
        } elseif (! $row->forced) {
            // Without FORCE the table's owner silently bypasses the policy — and the
            // owner is who runs migrations and seeders.
            $offenders[] = "{$table}: RLS activado pero NO forzado";
        }
    }

    expect($offenders)->toBe([], "RLS incompleto:\n".implode("\n", $offenders));
});

it('every tenant table has its isolation policy', function (): void {
    $offenders = [];

    foreach (businessTables() as $table) {
        $policy = DB::select(
            'select 1 from pg_policies where schemaname = ? and tablename = ? and policyname = ?',
            ['public', $table, 'tenant_isolation'],
        );

        if ($policy === []) {
            $offenders[] = $table;
        }
    }

    expect($offenders)->toBe([], 'Sin política tenant_isolation: '.implode(', ', $offenders));
});

it('every index on a tenant table starts with tenant_id', function (): void {
    // The order is not an aesthetic preference. Every query carries
    // `where tenant_id = ?`, so an index that starts elsewhere makes PostgreSQL
    // walk other tenants' rows to discard them.
    $business = businessTables();
    $offenders = [];

    foreach ($business as $table) {
        $indexes = DB::select(
            "select i.relname as name, a.attname as first_column
             from pg_index x
             join pg_class t on t.oid = x.indrelid
             join pg_class i on i.oid = x.indexrelid
             join pg_attribute a on a.attrelid = t.oid and a.attnum = x.indkey[0]
             where t.relname = ? and t.relnamespace = 'public'::regnamespace",
            [$table],
        );

        foreach ($indexes as $index) {
            if (str_ends_with($index->name, '_pkey')) {
                continue;
            }

            if (array_key_exists($index->name, TenantSchema::GLOBAL_UNIQUE_INDEXES)) {
                continue;
            }

            if ($index->first_column !== 'tenant_id') {
                $offenders[] = "{$table}.{$index->name} empieza por «{$index->first_column}»";
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'Índices que no empiezan por tenant_id:',
        ...$offenders,
        '',
        'Usa TenantSchema::index() / ::uniquePerTenant(), que lo anteponen',
        'solos. Si el índice tiene que ser global de verdad, decláralo en',
        'TenantSchema::GLOBAL_UNIQUE_INDEXES con la razón.',
    ]));
});

it('every foreign key between tenant tables is composite', function (): void {
    // A simple FK `product_id -> products.id` allows one tenant's order to
    // reference another's product: a perfectly valid row to the database, found
    // out months later when a report does not add up. The composite
    // `(tenant_id, product_id)` makes it impossible.
    $business = businessTables();
    $offenders = [];

    $constraints = DB::select(
        "select c.conname as name,
                t.relname as source_table,
                r.relname as target_table,
                array_length(c.conkey, 1) as columns
         from pg_constraint c
         join pg_class t on t.oid = c.conrelid
         join pg_class r on r.oid = c.confrelid
         where c.contype = 'f' and t.relnamespace = 'public'::regnamespace"
    );

    foreach ($constraints as $fk) {
        $bothAreBusiness = in_array($fk->source_table, $business, true)
            && in_array($fk->target_table, $business, true);

        if ($bothAreBusiness && (int) $fk->columns < 2) {
            $offenders[] = "{$fk->name}: {$fk->source_table} → {$fk->target_table} es simple";
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'Claves foráneas simples entre tablas de negocio:',
        ...$offenders,
        '',
        'Usa TenantSchema::references(), que siempre crea',
        '(tenant_id, columna) → (tenant_id, id).',
    ]));
});
