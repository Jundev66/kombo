<?php

declare(strict_types=1);

namespace Platform\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Modules\ModuleRegistry;

/**
 * Brings a tenant's base roles up to date with the catalog.
 *
 * It only ADDS, never deletes a row — but base roles belong to the catalog, so
 * a catalog permission that is missing comes back. A role the tenant invented,
 * with a code the catalog does not know, is left alone. Idempotent through the
 * two unique indexes the schema already declares.
 *
 * It writes and filters `tenant_id` by hand: this also runs from a command and
 * from a seeder, where ambient isolation is not in place. See KMB-0007.
 */
final class RoleProvisioner
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * @return array{roles: int, permissions: int} How many rows were created.
     */
    public function reconcile(string $tenantId): array
    {
        $available = $this->modules->permissionsFor($this->activeModules($tenantId));

        $createdRoles = 0;
        $createdPermissions = 0;

        foreach (RoleCatalog::all() as $code => $catalog) {
            [$roleId, $created] = $this->roleId($tenantId, $code, $catalog);
            $createdRoles += $created ? 1 : 0;

            // The owner carries no rows: they resolve to `['*']`, expanded against
            // the modules switched on TODAY.
            if ($catalog['is_owner']) {
                continue;
            }

            foreach ($catalog['permissions'] as $permission => $requiresAuthorization) {
                // A permission of a switched-off module does not exist in the system, so
                // granting it would write a row that means nothing.
                if (! in_array($permission, $available, true)) {
                    continue;
                }

                $createdPermissions += DB::table('role_permissions')->insertOrIgnore([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'role_id' => $roleId,
                    'permission' => $permission,
                    'requires_authorization' => $requiresAuthorization,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return ['roles' => $createdRoles, 'permissions' => $createdPermissions];
    }

    /**
     * The modules this tenant really has.
     *
     * Must match `CapabilityResolver::computeForTenant()` exactly, or a granted
     * permission never resolves. Two non-obvious parts: the core is not in
     * `tenant_modules` at all (it cannot be switched off, so no row was ever
     * written), and the plan is the ceiling.
     *
     * @return list<string>
     */
    public function activeModules(string $tenantId): array
    {
        $planCode = DB::table('tenants')->where('id', $tenantId)->value('plan_code');

        $allowedByPlanCodes = DB::table('plan_modules')
            ->where('plan_code', $planCode)
            ->pluck('module_code')
            ->all();

        $enabledCodes = DB::table('tenant_modules')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->pluck('module_code')
            ->all();

        return array_values(array_unique([
            ...$this->modules->coreCodes(),
            ...array_intersect($enabledCodes, $allowedByPlanCodes),
        ]));
    }

    /**
     * The role id, creating it when missing.
     *
     * An existing role's name is left alone: the owner may have renamed it.
     *
     * @param  array{name: string, is_owner: bool, permissions: array<string, bool>}  $catalogEntry
     * @return array{0: string, 1: bool}
     */
    private function roleId(string $tenantId, string $code, array $catalog): array
    {
        $existing = DB::table('roles')
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->value('id');

        if ($existing !== null) {
            return [(string) $existing, false];
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

        return [$roleId, true];
    }
}
