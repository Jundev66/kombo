<?php

declare(strict_types=1);

namespace Platform\Capabilities;

/**
 * What this tenant, on this plan, with this user inside it, can do.
 *
 * Already resolved: the frontend paints it and decides nothing.
 */
final readonly class TenantCapabilities
{
    /**
     * @param  list<string>  $modules  Enabled AND allowed by the plan.
     * @param  list<string>  $permissions  The user's, filtered by active module.
     * @param  array<string, mixed>  $settings  `module.option` keys, effective values.
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
     * THE check.
     *
     * Always the conjunction: module enabled AND permission granted. A
     * permission of a disabled module never grants access, even when the role
     * has it written down — disabling the module unmade that permission.
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
