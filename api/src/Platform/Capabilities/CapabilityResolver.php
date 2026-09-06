<?php

declare(strict_types=1);

namespace Platform\Capabilities;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\DatabaseManager;
use Platform\Modules\ModuleRegistry;

/**
 * The hub: plan × enabled modules × settings → capabilities.
 *
 * The plan is the CEILING, `tenant_modules` is what is ON within it, and
 * `tenant_settings` is how it BEHAVES. Combining them in one place is what lets
 * the frontend decide nothing and the server validate against the same source
 * that painted the screen.
 */
final class CapabilityResolver
{
    private const TTL_SECONDS = 3600;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Cache $cache,
        private readonly ModuleRegistry $registry,
    ) {}

    /**
     * @param  list<string>  $userPermissions  `['*']` for an owner.
     */
    public function resolve(string $tenantId, string $planCode, array $userPermissions): TenantCapabilities
    {
        /** @var array{modules: list<string>, settings: array<string, mixed>, limits: array<string, int|null>, upgradeable: list<array{module: string, name: string, requiresPlan: string}>} $base */
        $base = $this->cache->remember(
            "caps:{$tenantId}",
            self::TTL_SECONDS,
            fn (): array => $this->computeForTenant($tenantId, $planCode),
        );

        $granted = $this->registry->permissionsFor($base['modules']);

        // An owner has `['*']`, expanded against the modules switched on today, so
        // enabling a new module makes it usable immediately.
        $permissions = in_array('*', $userPermissions, true)
            ? $granted
            : array_values(array_intersect($userPermissions, $granted));

        return new TenantCapabilities(
            modules: $base['modules'],
            permissions: $permissions,
            settings: $base['settings'],
            limits: new PlanLimits(...$base['limits']),
            upgradeable: $base['upgradeable'],
        );
    }

    /**
     * Call on enabling or disabling a module, changing a setting, or changing
     * plan. Forget it and the tenant sees yesterday's screen.
     */
    public function forget(string $tenantId): void
    {
        $this->cache->forget("caps:{$tenantId}");
    }

    /**
     * @return array{modules: list<string>, settings: array<string, mixed>, limits: array<string, int|null>, upgradeable: list<array{module: string, name: string, requiresPlan: string}>}
     */
    private function computeForTenant(string $tenantId, string $planCode): array
    {
        $allowedByPlan = $this->db->table('plan_modules')
            ->where('plan_code', $planCode)
            ->pluck('module_code')
            ->all();

        $enabledByTenant = $this->db->table('tenant_modules')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->pluck('module_code')
            ->all();

        $modules = array_values(array_unique([
            // The core does not depend on the plan and cannot be switched off.
            ...$this->registry->coreCodes(),
            ...array_intersect($enabledByTenant, $allowedByPlan),
        ]));

        // Orphan rows out: a module withdrawn from this version still has its
        // `tenant_modules` row. It is not deleted — if the module comes back, the
        // tenant gets it enabled again.
        $modules = array_values(array_filter($modules, $this->registry->has(...)));

        return [
            'modules' => $modules,
            'settings' => $this->resolveSettings($tenantId, $modules),
            'limits' => $this->resolveLimits($planCode),
            'upgradeable' => $this->resolveUpgradeable($modules),
        ];
    }

    /**
     * @param  list<string>  $modules
     * @return array<string, mixed>
     */
    private function resolveSettings(string $tenantId, array $modules): array
    {
        $settings = $this->registry->defaultSettingsFor($modules);

        $stored = $this->db->table('tenant_settings')
            ->where('tenant_id', $tenantId)
            ->get(['key', 'value']);

        foreach ($stored as $row) {
            $definition = $this->registry->settingDefinition((string) $row->key);

            // A key whose module no longer exists is IGNORED, not deleted: if the
            // module returns, the tenant gets its settings back unchanged.
            if ($definition === null) {
                continue;
            }

            $settings[(string) $row->key] = $definition->cast($row->value);
        }

        return $settings;
    }

    /**
     * @return array<string, int|null>
     */
    private function resolveLimits(string $planCode): array
    {
        $plan = $this->db->table('plans')->where('code', $planCode)->first();

        return [
            'maxUsers' => $plan?->max_users !== null ? (int) $plan->max_users : null,
            'maxProducts' => $plan?->max_products !== null ? (int) $plan->max_products : null,
            'maxOrdersMonth' => $plan?->max_orders_month !== null ? (int) $plan->max_orders_month : null,
        ];
    }

    /**
     * What this tenant does NOT have, with the cheapest plan that includes it.
     *
     * The only thing the UI shows deliberately disabled — locked, with its
     * price. Everything else that does not apply simply is not on the screen.
     *
     * @param  list<string>  $active
     * @return list<array{module: string, name: string, requiresPlan: string}>
     */
    private function resolveUpgradeable(array $active): array
    {
        $rows = $this->db->table('plan_modules')
            ->join('plans', 'plans.code', '=', 'plan_modules.plan_code')
            ->whereNotIn('plan_modules.module_code', $active)
            ->where('plans.is_public', true)
            ->orderBy('plans.sort_order')
            ->get(['plan_modules.module_code', 'plan_modules.plan_code']);

        $seen = [];
        $upgradeable = [];

        foreach ($rows as $row) {
            $code = (string) $row->module_code;

            // The first one is the cheapest plan: they come ordered by `sort_order`.
            if (isset($seen[$code]) || ! $this->registry->has($code)) {
                continue;
            }

            $seen[$code] = true;

            $upgradeable[] = [
                'module' => $code,
                'name' => $this->registry->get($code)->name(),
                'requiresPlan' => (string) $row->plan_code,
            ];
        }

        return $upgradeable;
    }
}
