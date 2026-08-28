<?php

declare(strict_types=1);

use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Platform\Auth\RoleCatalog;
use Platform\Modules\ModuleRegistry;
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
 * Hablan directamente con PostgreSQL en vez de pasar por TenantDatabaseGuard, y
 * es deliberado: ahí es donde vive la garantía. Si el aislamiento se sostiene
 * contra SQL crudo, se sostiene contra cualquier cosa que escriba encima.
 *
 * Ojo con el orden en las pruebas: el contexto va ANTES de escribir cualquier
 * fila de negocio. `WITH CHECK` rechaza un insert sin negocio en contexto, y
 * eso es correcto — no es un estorbo del helper.
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
    // Se siembran los planes DE VERDAD, no una fila mínima: si el helper
    // inventara sus propios límites, las pruebas comprobarían un plan que no
    // existe en producción y pasarían en verde con los techos mal puestos.
    if (! DB::table('plans')->where('code', $plan)->exists()) {
        (new PlanSeeder)->run();
    }

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

/** Crea un usuario dentro de un negocio. */
function makeUser(
    string $tenantId,
    string $email,
    string $name = 'Prueba',
    string $password = 'demo1234',
    ?string $pin = null,
): string {
    $id = (string) Str::uuid7();

    DB::table('users')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'name' => $name,
        'email' => $email,
        'password' => Hash::make($password),
        'pin_hash' => $pin === null ? null : Hash::make($pin),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * Crea un rol del catálogo base y se lo asigna a un usuario.
 *
 * Concede sólo los permisos de módulos que el negocio tenga encendidos, igual
 * que hace la siembra real: un permiso de un módulo apagado no existe.
 */
function giveRole(string $tenantId, string $userId, string $code): string
{
    $catalogo = RoleCatalog::get($code);
    $roleId = (string) Str::uuid7();

    DB::table('roles')->insert([
        'id' => $roleId,
        'tenant_id' => $tenantId,
        'code' => $code,
        'name' => $catalogo['name'],
        'is_system' => true,
        'is_owner' => $catalogo['is_owner'],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $activos = DB::table('tenant_modules')
        ->where('tenant_id', $tenantId)->where('enabled', true)->pluck('module_code')->all();

    $disponibles = app(ModuleRegistry::class)->permissionsFor($activos);

    foreach ($catalogo['permissions'] as $permission => $requiereAutorizacion) {
        if (! in_array($permission, $disponibles, true)) {
            continue;
        }

        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
            'permission' => $permission,
            'requires_authorization' => $requiereAutorizacion,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('role_user')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $roleId;
}

/** Enciende un módulo para un negocio. */
function enableModule(string $tenantId, string $moduleCode): void
{
    DB::table('tenant_modules')->insertOrIgnore([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'module_code' => $moduleCode,
        'enabled' => true,
        'enabled_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Las cabeceras con las que un NAVEGADOR llegaría desde el subdominio de ese
 * negocio.
 *
 * `Origin` no es decorativo: Sanctum decide si una petición viene «del
 * frontend» —y por tanto si le da sesión— mirando `Origin` o `Referer`, no el
 * Host. Sin esto, el login responde que no hay sesión y parece un fallo del
 * servidor.
 *
 * @return array<string, string>
 */
function browsingAs(string $slug): array
{
    return [
        'Origin' => "http://{$slug}.localhost",
        'Referer' => "http://{$slug}.localhost/",
        'Accept' => 'application/json',
    ];
}

/** La URL absoluta de una ruta dentro del subdominio de un negocio. */
function urlFor(string $slug, string $path): string
{
    return "http://{$slug}.localhost{$path}";
}
