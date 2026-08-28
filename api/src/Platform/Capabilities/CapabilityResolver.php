<?php

declare(strict_types=1);

namespace Platform\Capabilities;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\DatabaseManager;
use Platform\Modules\ModuleRegistry;

/**
 * El eje del sistema: plan × módulos encendidos × configuración → capacidades.
 *
 * Los tres niveles se combinan **en este orden**, y cada uno significa una cosa
 * distinta:
 *
 *   1. **El plan** es el TECHO: qué puede llegar a tener este negocio.
 *   2. **`tenant_modules`** es lo ENCENDIDO: qué tiene hoy, dentro de ese techo.
 *   3. **`tenant_settings`** es el COMPORTAMIENTO: cómo lo quiere.
 *
 * Combinarlos en un solo sitio es lo que permite que el frontend no decida
 * nada y que el servidor valide contra exactamente la misma fuente que pintó
 * la pantalla.
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
     * @param  list<string>  $userPermissions  `['*']` si es el dueño.
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

        // El dueño tiene `['*']`, que se EXPANDE contra los módulos que el
        // negocio tenga encendidos hoy. No se le guardan permisos uno a uno:
        // así, cuando enciende un módulo nuevo, ya puede usarlo.
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
     * Hay que llamarlo al encender o apagar un módulo, al cambiar un ajuste y
     * al cambiar de plan. Si se olvida, el negocio ve la pantalla de ayer.
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
            // El núcleo NO depende del plan y no se apaga. Es lo mínimo sin lo
            // cual el sistema no es un sistema.
            ...$this->registry->coreCodes(),
            ...array_intersect($enabledByTenant, $allowedByPlan),
        ]));

        // Filas huérfanas fuera: si un módulo se retiró de esta versión, su
        // fila en `tenant_modules` sigue ahí y no debe contar. No se borra —si
        // el módulo vuelve, el negocio lo recupera encendido.
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

            // Una clave cuyo módulo ya no existe se IGNORA, no se borra: si el
            // módulo vuelve, el negocio recupera su configuración tal cual.
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
     * Lo que este negocio NO tiene, con el plan más barato que lo incluye.
     *
     * Es lo único que la interfaz muestra deshabilitado a propósito: bloqueado
     * y con su precio al lado. Todo lo demás que no aplica sencillamente no
     * existe en la pantalla.
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

            // El primero que aparece es el plan más barato, porque vienen
            // ordenados por `sort_order`.
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
