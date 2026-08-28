<?php

declare(strict_types=1);

namespace Platform\Capabilities;

/**
 * Qué puede hacer este negocio, con este plan, y este usuario dentro de él.
 *
 * Ya resuelto: el servidor combinó plan × módulos encendidos × configuración ×
 * permisos del usuario, y esto es el resultado. El frontend lo pinta y no
 * decide nada.
 */
final readonly class TenantCapabilities
{
    /**
     * @param  list<string>  $modules  Encendidos Y permitidos por el plan.
     * @param  list<string>  $permissions  Los del usuario, ya filtrados por módulo activo.
     * @param  array<string, mixed>  $settings  Claves `modulo.opcion`, con el valor efectivo.
     * @param  list<array{module: string, name: string, requiresPlan: string}>  $upgradeable
     */
    public function __construct(
        public array $modules,
        public array $permissions,
        public array $settings,
        public PlanLimits $limits,
        public array $upgradeable = [],
    ) {}

    public function hasModule(string $code): bool
    {
        return in_array($code, $this->modules, true);
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * LA comprobación del sistema.
     *
     * Siempre la conjunción: **módulo encendido Y permiso concedido**. Un
     * permiso de un módulo apagado nunca concede acceso, aunque el rol lo
     * tenga escrito — al apagar el módulo, ese permiso dejó de existir.
     */
    public function allows(string $module, string $permission): bool
    {
        return $this->hasModule($module) && $this->can($permission);
    }

    public function setting(string $qualifiedKey, mixed $fallback = null): mixed
    {
        return $this->settings[$qualifiedKey] ?? $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'modules' => $this->modules,
            'permissions' => $this->permissions,
            'settings' => $this->settings,
            'limits' => $this->limits->toArray(),
            'upgradeable' => $this->upgradeable,
        ];
    }
}
