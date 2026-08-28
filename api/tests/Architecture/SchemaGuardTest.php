<?php

declare(strict_types=1);

/*
 * El esquema, verificado contra el catálogo real de PostgreSQL.
 *
 * Las otras pruebas de arquitectura leen ficheros. Estas preguntan a la base
 * de datos cómo quedó de verdad, que es lo único que importa: una migración
 * puede llamar a TenantSchema::create() y aun así alguien puede haber añadido
 * un índice a mano después.
 *
 * Si una de estas falla, la respuesta es arreglar la migración usando
 * TenantSchema, no añadir la tabla a la lista de excepciones.
 */

use Illuminate\Support\Facades\DB;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Toda tabla que NO sea de plataforma ni de infraestructura.
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

it('toda tabla de negocio tiene tenant_id', function (): void {
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

it('toda tabla de negocio tiene RLS activado Y forzado', function (): void {
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
            // Sin FORCE, el dueño de la tabla se salta la política sin avisar
            // — y el dueño es quien corre migraciones y seeders.
            $offenders[] = "{$table}: RLS activado pero NO forzado";
        }
    }

    expect($offenders)->toBe([], "RLS incompleto:\n".implode("\n", $offenders));
});

it('toda tabla de negocio tiene su política de aislamiento', function (): void {
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

it('todo índice de una tabla de negocio empieza por tenant_id', function (): void {
    // El orden no es preferencia estética. Toda consulta lleva
    // `where tenant_id = ?`; un índice que no empiece por ahí obliga a
    // PostgreSQL a recorrer filas de otros negocios para descartarlas. Con un
    // año de pedidos y una máquina modesta, eso es la diferencia entre que el
    // tablero de cocina cargue en 40 ms o en dos segundos.
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

it('toda clave foránea entre tablas de negocio es compuesta', function (): void {
    // Una FK simple `producto_id -> productos.id` permite meter en el pedido
    // de un negocio el producto de otro: es una fila perfectamente válida para
    // la base de datos, y el error se descubre meses después cuando un reporte
    // no cuadra. La compuesta `(tenant_id, producto_id)` lo hace imposible.
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
