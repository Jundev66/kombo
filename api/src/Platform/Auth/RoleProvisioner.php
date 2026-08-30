<?php

declare(strict_types=1);

namespace Platform\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Modules\ModuleRegistry;

/**
 * Poner al día los roles base de un negocio contra el catálogo.
 *
 * Existe porque `role_permissions` sólo se escribía al dar de alta el negocio.
 * Eso significaba que ampliar `RoleCatalog` —darle el horario al encargado, por
 * ejemplo— **no llegaba a ningún negocio que ya existiera**: el código nuevo se
 * desplegaba, la tabla seguía con las filas viejas, y el encargado seguía sin
 * poder. Un cambio que no falla y tampoco hace nada es de los peores que hay.
 *
 * **Sólo añade: nunca borra una fila.** Pero conviene ser exacto sobre lo que
 * eso significa, porque no es «respeta lo que hayas cambiado a mano»: si un
 * permiso del catálogo falta, vuelve. Los roles base son del catálogo, y su
 * contenido lo decide el catálogo — para eso son `is_system`. Un rol propio del
 * negocio, con un código que no está en `RoleCatalog`, ni se mira.
 *
 * Idempotente por construcción: se apoya en los dos únicos que ya declara el
 * esquema —`(tenant_id, code)` en `roles` y `(tenant_id, role_id, permission)`
 * en `role_permissions`— así que correrlo dos veces no duplica una fila.
 *
 * Por qué existe y qué se descartó: KMB-0007.
 *
 * **Escribe `tenant_id` a mano y filtra por él a mano.** No se apoya en el
 * aislamiento ambiental a propósito: esto corre tanto dentro de una petición
 * como desde un comando y desde un seeder —que va como dueño del esquema y se
 * salta RLS—, y una consulta que DECIDE algo («¿existe ya este rol?») no puede
 * depender de qué contexto había puesto.
 */
final class RoleProvisioner
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * @return array{roles: int, permissions: int} Cuántas filas se crearon.
     */
    public function reconcile(string $tenantId): array
    {
        $disponibles = $this->modules->permissionsFor($this->activeModules($tenantId));

        $rolesCreados = 0;
        $permisosCreados = 0;

        foreach (RoleCatalog::all() as $code => $catalogo) {
            [$roleId, $creado] = $this->roleId($tenantId, $code, $catalogo);
            $rolesCreados += $creado ? 1 : 0;

            // El dueño no lleva filas: se resuelve como `['*']` y se expande
            // contra los módulos encendidos HOY.
            if ($catalogo['is_owner']) {
                continue;
            }

            foreach ($catalogo['permissions'] as $permission => $requiereAutorizacion) {
                // Un permiso de un módulo apagado no existe en el sistema, así
                // que concederlo sería escribir una fila que no significa nada.
                if (! in_array($permission, $disponibles, true)) {
                    continue;
                }

                $permisosCreados += DB::table('role_permissions')->insertOrIgnore([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'role_id' => $roleId,
                    'permission' => $permission,
                    'requires_authorization' => $requiereAutorizacion,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return ['roles' => $rolesCreados, 'permissions' => $permisosCreados];
    }

    /**
     * Los módulos que este negocio tiene DE VERDAD.
     *
     * Es la misma cuenta que hace `CapabilityResolver::computeForTenant()`, y
     * tiene que serlo: si aquí se concede un permiso que allí no se resuelve,
     * la fila existe y no sirve; si aquí se omite uno que allí sí cuenta, el
     * rol se queda corto y nadie sabe por qué.
     *
     * Las dos piezas que no son obvias:
     *
     * - **El núcleo no está en `tenant_modules`.** No depende del plan y no se
     *   apaga, así que nunca se le escribió una fila. Leer sólo esa tabla —que
     *   es lo que se hacía— dejaba fuera `settings.manage`, `users.manage` y
     *   `audit.view`: los permisos de configurar el negocio no llegaban a
     *   ningún rol que no fuera el dueño, y el síntoma era un encargado que no
     *   podía tocar el horario sin ningún error que lo explicara.
     * - **El plan es el techo.** Un módulo encendido que el plan ya no incluye
     *   no cuenta al resolver, así que tampoco debe repartir permisos.
     *
     * @return list<string>
     */
    public function activeModules(string $tenantId): array
    {
        $planCode = DB::table('tenants')->where('id', $tenantId)->value('plan_code');

        $permitidosPorPlan = DB::table('plan_modules')
            ->where('plan_code', $planCode)
            ->pluck('module_code')
            ->all();

        $encendidos = DB::table('tenant_modules')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->pluck('module_code')
            ->all();

        return array_values(array_unique([
            ...$this->modules->coreCodes(),
            ...array_intersect($encendidos, $permitidosPorPlan),
        ]));
    }

    /**
     * El identificador del rol, creándolo si no estaba.
     *
     * No se toca el nombre de uno que ya existe: el dueño puede haberlo
     * renombrado —«Cajero» en vez de «Mostrador»— y machacárselo en cada pasada
     * sería una sorpresa desagradable que además nadie relacionaría con esto.
     *
     * @param  array{name: string, is_owner: bool, permissions: array<string, bool>}  $catalogo
     * @return array{0: string, 1: bool}
     */
    private function roleId(string $tenantId, string $code, array $catalogo): array
    {
        $existente = DB::table('roles')
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->value('id');

        if ($existente !== null) {
            return [(string) $existente, false];
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

        return [$roleId, true];
    }
}
