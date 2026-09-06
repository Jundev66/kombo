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
 * Architecture tests walk files and PostgreSQL's catalog: they need the
 * application booted but touch no data.
 */
pest()->extend(TestCase::class)->in('Architecture');

/*
 * Feature and Isolation do write. DatabaseTransactions rather than
 * RefreshDatabase: re-migrating between tests against PostgreSQL with RLS costs
 * seconds per file, which turns the suite into something nobody runs.
 */
pest()->extend(TestCase::class)->use(DatabaseTransactions::class)->in('Feature', 'Isolation');

/*
 * Unit deliberately does not extend TestCase: these are value objects and state
 * machines, plain PHP. Booting Laravel to check that 100 split three ways gives
 * [34, 33, 33] buys nothing.
 */

pest()->group('arch')->in('Architecture');
pest()->group('isolation')->in('Isolation');

/*
 * ── Helpers ─────────────────────────────────────────────────────────────────
 *
 * They talk straight to PostgreSQL rather than going through
 * TenantDatabaseGuard, deliberately: that is where the guarantee lives. If
 * isolation holds against raw SQL, it holds against anything written on top.
 *
 * Mind the order: context goes BEFORE writing any tenant row. `WITH CHECK`
 * rejects an insert with no tenant in context, and that is correct.
 */

/**
 * Pins the current tenant, in BOTH layers.
 *
 * `ResolveTenant` does exactly this on every request, and both are needed:
 * PostgreSQL so RLS filters, and `TenantContext` so `BelongsToTenant` knows
 * which `tenant_id` to write.
 */
function actingForTenant(string $tenantId): void
{
    DB::statement('select set_config(?, ?, false)', [TenantSchema::GUC, $tenantId]);

    app(TenantContext::class)->set(new Tenant(
        id: $tenantId,
        slug: 'pruebas',
        name: 'Pruebas',
        planCode: 'business',
        status: TenantStatus::Active,
    ));

    // Capabilities are memoised per request; in a test that switches tenant
    // mid-function, that memo would belong to the previous one.
    app(CurrentCapabilities::class)->reset();
}

/** Leaves the connection with NO tenant, as it would return to the pool. */
function withoutTenant(): void
{
    DB::statement('select set_config(?, ?, false)', [TenantSchema::GUC, '']);
    app(TenantContext::class)->forget();
    app(CurrentCapabilities::class)->reset();
}

/**
 * Creates a tenant. `tenants` is a platform table — queried to find out which
 * tenant we mean — so it has no RLS and can be inserted without context.
 */
function makeTenant(string $slug, string $plan = 'business'): string
{
    // The REAL plans are seeded, not a minimal row: a helper inventing its own
    // limits would test a plan that does not exist in production and pass green
    // with the ceilings wrong.
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

/** Creates a user inside a tenant. */
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
 * Assigns a base-catalog role to a user, creating it if missing.
 *
 * It reuses an existing role, as production does: roles are seeded once at
 * sign-up and two kitchen staff share one. Always creating blew up against the
 * unique `(tenant_id, code)` as soon as two tests handed out the same role.
 *
 * Permissions are granted only for modules the tenant has on, and that count is
 * made by `RoleProvisioner` — the same object the real seeding uses, not a copy.
 *
 * Two separate counts is exactly why a bug took so long to see: this read
 * `tenant_modules` alone, while `core` never has a row there, so
 * `settings.manage` and `users.manage` reached no role. The test world was more
 * generous than the real one, the worst direction to differ in.
 */
function giveRole(string $tenantId, string $userId, string $code): string
{
    $catalog = RoleCatalog::get($code);

    $existing = DB::table('roles')
        ->where('tenant_id', $tenantId)
        ->where('code', $code)
        ->value('id');

    if ($existing !== null) {
        DB::table('role_user')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'role_id' => $existing,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) $existing;
    }

    $roleId = (string) Str::uuid7();

    DB::table('roles')->insert([
        'id' => $roleId,
        'tenant_id' => $tenantId,
        'code' => $code,
        'name' => $catalog['name'],
        'is_system' => true,
        'is_owner' => $catalog['is_owner'],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $provisioner = app(RoleProvisioner::class);
    $available = app(ModuleRegistry::class)->permissionsFor($provisioner->activeModules($tenantId));

    foreach ($catalog['permissions'] as $permission => $requiresAuthorization) {
        if (! in_array($permission, $available, true)) {
            continue;
        }

        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
            'permission' => $permission,
            'requires_authorization' => $requiresAuthorization,
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

/** Switches a module on for a tenant. */
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
 * The headers a BROWSER would arrive with from that tenant's subdomain.
 *
 * `Origin` is not decorative: Sanctum decides whether a request comes "from the
 * frontend" — and therefore gets a session — by looking at `Origin` or
 * `Referer`, not the Host.
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

/** The absolute URL of a route inside a tenant's subdomain. */
function urlFor(string $slug, string $path): string
{
    return "http://{$slug}.localhost{$path}";
}

/**
 * Signs into a tenant's dashboard with email and password, leaving the session
 * cookie on the test client.
 *
 * Here rather than in a test file because several suites need it, and a
 * function declared in a test file only exists when that file is loaded.
 */
function loginAs(string $slug, string $email, string $password = 'demo1234'): void
{
    test()->withHeaders(browsingAs($slug))
        ->postJson(urlFor($slug, '/api/v1/auth/login'), ['email' => $email, 'password' => $password])
        ->assertOk();
}
