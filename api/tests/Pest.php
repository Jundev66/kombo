<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Tenancy\Database\TenantSchema;
use Tests\TestCase;

/*
 * Las pruebas de arquitectura recorren ficheros y el catálogo de PostgreSQL;
 * necesitan la aplicación arrancada pero no tocan datos.
 */
pest()->extend(TestCase::class)->in('Architecture');

/*
 * Feature e Isolation sí escriben. DatabaseTransactions y no RefreshDatabase:
 * volver a migrar entre pruebas contra PostgreSQL con RLS son varios segundos
 * por fichero, y en una máquina modesta eso convierte la suite en algo que
 * nadie corre.
 */
pest()->extend(TestCase::class)->use(DatabaseTransactions::class)->in('Feature', 'Isolation');

/*
 * Unit NO extiende TestCase a propósito: son value objects y máquinas de
 * estado, PHP puro. Arrancar Laravel para comprobar que 100 entre 3 reparte
 * [34, 33, 33] cuesta cientos de milisegundos por fichero y no compra nada.
 */

pest()->group('arch')->in('Architecture');
pest()->group('isolation')->in('Isolation');

/*
 * ── Ayudantes ───────────────────────────────────────────────────────────────
 *
 * En Fase 1 esto pasará por TenantContext y TenantDatabaseGuard. Mientras
 * tanto hablan directamente con PostgreSQL, que es de todos modos donde vive
 * la garantía: si el aislamiento se sostiene con SQL crudo, se sostiene.
 */

/** Fija el negocio en curso para esta conexión. */
function actingForTenant(string $tenantId): void
{
    DB::statement('select set_config(?, ?, false)', [TenantSchema::GUC, $tenantId]);
}

/** Deja la conexión SIN negocio, como quedaría al devolverla al pool. */
function withoutTenant(): void
{
    DB::statement('select set_config(?, ?, false)', [TenantSchema::GUC, '']);
}

/**
 * Crea un negocio. `tenants` es tabla de plataforma —se consulta para
 * averiguar de qué negocio hablamos— así que no lleva RLS y se puede insertar
 * sin contexto.
 */
function makeTenant(string $slug, string $plan = 'negocio'): string
{
    DB::table('plans')->insertOrIgnore([
        'code' => $plan,
        'name' => ucfirst($plan),
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $id = (string) Str::uuid7();

    DB::table('tenants')->insert([
        'id' => $id,
        'slug' => $slug,
        'name' => ucfirst($slug),
        'plan_code' => $plan,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/** Crea un usuario dentro del negocio que esté en contexto. */
function makeUser(string $tenantId, string $email): string
{
    $id = (string) Str::uuid7();

    DB::table('users')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'name' => 'Prueba',
        'email' => $email,
        'password' => 'x',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}
