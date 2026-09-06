<?php

declare(strict_types=1);

/*
 * That widening the role catalog actually reaches somebody.
 *
 * Until `roles:reconcile` existed, `role_permissions` was only written at
 * sign-up. Giving the manager the opening hours served tenants registered after
 * that deployment and nobody else: the code shipped, nothing failed, and a
 * six-month-old shop's manager still could not. A change that neither breaks
 * nor does anything takes months to discover.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Auth\RoleProvisioner;

beforeEach(function (): void {
    $this->slug = 'reparto-'.Str::lower(Str::random(6));
    $this->tenant = makeTenant($this->slug, plan: 'business');

    actingForTenant($this->tenant);

    foreach (['catalog', 'orders', 'counter', 'channels'] as $module) {
        enableModule($this->tenant, $module);
    }
});

/** The permissions a base role of this tenant has right now. */
function permissionsOfRole(string $tenantId, string $code): array
{
    return DB::table('role_permissions')
        ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
        ->where('roles.tenant_id', $tenantId)
        ->where('roles.code', $code)
        ->orderBy('permission')
        ->pluck('permission')
        ->all();
}

it('core permissions reach the manager, even though core is not in tenant_modules', function (): void {
    /*
     * The test for a bug that lived a long time unseen.
     *
     * `core` does not depend on the plan and cannot be switched off, so it never
     * has a `tenant_modules` row. Seeding read only that table, so
     * `settings.manage`, `users.manage` and `audit.view` reached no role but the
     * owner's — and the symptom was a manager who could not touch the opening
     * hours, with no error to explain it.
     */
    app(RoleProvisioner::class)->reconcile($this->tenant);

    expect(permissionsOfRole($this->tenant, 'manager'))
        ->toContain('settings.manage')
        ->toContain('users.manage')
        ->toContain('audit.view');
});

it('an older tenant recovers the permissions it was missing', function (): void {
    app(RoleProvisioner::class)->reconcile($this->tenant);

    // How the tenant was signed up under the old catalog.
    DB::table('role_permissions')
        ->whereIn('role_id', DB::table('roles')->where('tenant_id', $this->tenant)->where('code', 'manager')->pluck('id'))
        ->whereIn('permission', ['settings.manage', 'users.manage'])
        ->delete();

    expect(permissionsOfRole($this->tenant, 'manager'))->not->toContain('settings.manage');

    $this->artisan('roles:reconcile', ['--tenant' => $this->slug])->assertSuccessful();

    expect(permissionsOfRole($this->tenant, 'manager'))
        ->toContain('settings.manage')
        ->toContain('users.manage');
});

it('running it twice duplicates not a single row', function (): void {
    $this->artisan('roles:reconcile', ['--tenant' => $this->slug])->assertSuccessful();

    $first = permissionsOfRole($this->tenant, 'manager');

    $this->artisan('roles:reconcile', ['--tenant' => $this->slug])->assertSuccessful();

    expect(permissionsOfRole($this->tenant, 'manager'))->toBe($first);
});

it('a system role goes back to what the catalog says', function (): void {
    /*
     * It only adds rows, never deletes — but that does NOT mean "respects what
     * you changed by hand": what is missing comes back.
     *
     * Right for base roles, which are `is_system` and not editable from any
     * screen: their content is the catalog's call. If an owner is ever allowed
     * to remove a single permission from their manager, that decision will need
     * storing somewhere — a deletion is not a decision, it is a missing row.
     */
    app(RoleProvisioner::class)->reconcile($this->tenant);

    $manager = DB::table('roles')->where('tenant_id', $this->tenant)->where('code', 'manager')->value('id');

    DB::table('role_permissions')
        ->where('role_id', $manager)
        ->where('permission', 'catalog.change_price')
        ->delete();

    $this->artisan('roles:reconcile', ['--tenant' => $this->slug])->assertSuccessful();

    expect(permissionsOfRole($this->tenant, 'manager'))->toContain('catalog.change_price');
});

it('a role the tenant invented is not even looked at', function (): void {
    // Only codes in the catalog are touched. One the tenant invented on top is
    // none of this code's business.
    app(RoleProvisioner::class)->reconcile($this->tenant);

    $own = (string) Str::uuid7();

    DB::table('roles')->insert([
        'id' => $own,
        'tenant_id' => $this->tenant,
        'code' => 'fin_de_semana',
        'name' => 'Fin de semana',
        'is_system' => false,
        'is_owner' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('role_permissions')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant,
        'role_id' => $own,
        'permission' => 'orders.view',
        'requires_authorization' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('roles:reconcile', ['--tenant' => $this->slug])->assertSuccessful();

    expect(permissionsOfRole($this->tenant, 'fin_de_semana'))->toBe(['orders.view']);
});

it('grants no permissions of a module the tenant does not have', function (): void {
    // `delivery` is not on for this tenant, so its permissions do not exist in
    // the system and writing the row would write something meaningless.
    app(RoleProvisioner::class)->reconcile($this->tenant);

    expect(permissionsOfRole($this->tenant, 'manager'))
        ->not->toContain('delivery.manage')
        ->toContain('counter.sell');
});

it('the plan is the ceiling: an enabled module the plan no longer includes grants nothing', function (): void {
    /*
     * The same count `CapabilityResolver` makes, and it has to be: a permission
     * granted here that does not resolve there is a row that exists and does
     * nothing, and explains very badly why something "that is there" fails.
     */
    $slug = 'inicial-'.Str::lower(Str::random(6));
    $small = makeTenant($slug, plan: 'starter');

    actingForTenant($small);
    enableModule($small, 'catalog');
    // The starter plan does not include the till. The row exists; the ceiling
    enableModule($small, 'counter');

    app(RoleProvisioner::class)->reconcile($small);

    expect(permissionsOfRole($small, 'manager'))
        ->not->toContain('counter.sell')
        ->toContain('catalog.manage');
});

it('the owner carries no permission rows', function (): void {
    // rules.
    // Resolved as `['*']` and expanded against the modules on TODAY. Stored one
    // by one, a newly enabled module would be unusable until somebody added them.
    app(RoleProvisioner::class)->reconcile($this->tenant);

    expect(permissionsOfRole($this->tenant, 'owner'))->toBe([]);
});
