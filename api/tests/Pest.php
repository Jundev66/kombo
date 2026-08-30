<?php

declare(strict_types=1);

use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Platform\Auth\RoleCatalog;
use Platform\Auth\RoleProvisioner;
use Platform\Capabilities\CurrentCapabilities;
use Platform\Modules\ModuleRegistry;
use Platform\Tenancy\Database\TenantSchema;
use Platform\Tenancy\Tenant;
use Platform\Tenancy\TenantContext;
use Platform\Tenancy\TenantStatus;
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

/**
 * Fija el negocio en curso, en las DOS capas.
 *
 * `ResolveTenant` hace exactamente esto en cada petición, y hacen falta las
 * dos: PostgreSQL para que RLS filtre, y `TenantContext` para que el trait
 * `BelongsToTenant` sepa qué `tenant_id` poner al crear. Fijar sólo una deja
 * un entorno que no se parece a ninguna petición real.
 */
function actingForTenant(string $tenantId): void
{
    DB::statement('select set_config(?, ?, false)', [TenantSchema::GUC, $tenantId]);

    app(TenantContext::class)->set(new Tenant(
        id: $tenantId,
        slug: 'pruebas',
        name: 'Pruebas',
        planCode: 'negocio',
        status: TenantStatus::Active,
    ));

    // Las capacidades se memorizan por petición; en una prueba que cambia de
    // negocio a media función, esa memoria sería del negocio anterior.
    app(CurrentCapabilities::class)->reset();
}

/** Deja la conexión SIN negocio, como quedaría al devolverla al pool. */
function withoutTenant(): void
{
    DB::statement('select set_config(?, ?, false)', [TenantSchema::GUC, '']);
    app(TenantContext::class)->forget();
    app(CurrentCapabilities::class)->reset();
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
 * Asigna un rol del catálogo base a un usuario, **creándolo si no existe**.
 *
 * Reutiliza el rol si ya está, igual que en producción: los roles se siembran
 * una vez al dar de alta el negocio, y dos personas de cocina comparten el
 * mismo. Crearlo siempre reventaba contra el único `(tenant_id, code)` en
 * cuanto dos pruebas del mismo fichero repartían el mismo rol — un fallo que no
 * dice nada sobre lo que se estaba probando.
 *
 * Concede sólo los permisos de módulos que el negocio tenga encendidos, y la
 * cuenta de cuáles son ésos la hace `RoleProvisioner` — el mismo objeto que usa
 * la siembra real, no una copia.
 *
 * Que fueran dos cuentas distintas es exactamente por lo que un fallo tardó en
 * verse: aquí se leía `tenant_modules` a secas, y las pruebas encendían `core`
 * a mano en esa tabla. En producción `core` nunca tiene fila —no depende del
 * plan y no se apaga—, así que `settings.manage` y `users.manage` no llegaban a
 * ningún rol. El mundo de las pruebas era más generoso que el real, que es la
 * peor dirección en la que pueden diferir.
 */
function giveRole(string $tenantId, string $userId, string $code): string
{
    $catalogo = RoleCatalog::get($code);

    $existente = DB::table('roles')
        ->where('tenant_id', $tenantId)
        ->where('code', $code)
        ->value('id');

    if ($existente !== null) {
        DB::table('role_user')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'role_id' => $existente,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) $existente;
    }

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

    $provisioner = app(RoleProvisioner::class);
    $disponibles = app(ModuleRegistry::class)->permissionsFor($provisioner->activeModules($tenantId));

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

/**
 * Entra al panel de un negocio con correo y contraseña.
 *
 * Deja la cookie de sesión puesta en el cliente de pruebas, así que las
 * llamadas siguientes van autenticadas. Vive aquí y no dentro de un fichero de
 * pruebas porque lo necesitan varias suites — y una función declarada en un
 * fichero de test sólo existe cuando ESE fichero se carga, que es un fallo
 * desconcertante cuando se corre una suite sola.
 */
function entrarComo(string $slug, string $email, string $password = 'demo1234'): void
{
    test()->withHeaders(browsingAs($slug))
        ->postJson(urlFor($slug, '/api/v1/auth/login'), ['email' => $email, 'password' => $password])
        ->assertOk();
}
