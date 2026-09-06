<?php

declare(strict_types=1);

namespace Platform\Modules;

use LogicException;
use Platform\Modules\Exceptions\UnknownModule;

/**
 * Which modules EXIST in this version of the system.
 *
 * Declared in `config/modules.php` rather than discovered by walking
 * directories: what exists should not depend on which files survived a
 * half-finished deployment.
 */
final class ModuleRegistry
{
    /** @var array<string, ModuleManifest> */
    private array $modules = [];

    public function register(ModuleManifest $manifest): void
    {
        $this->modules[$manifest->code()] = $manifest;
    }

    public function has(string $code): bool
    {
        return isset($this->modules[$code]);
    }

    public function get(string $code): ModuleManifest
    {
        return $this->modules[$code] ?? throw new UnknownModule($code);
    }

    /** @return array<string, ModuleManifest> */
    public function all(): array
    {
        return $this->modules;
    }

    /** @return list<string> */
    public function coreCodes(): array
    {
        return array_values(array_map(
            fn (ModuleManifest $m): string => $m->code(),
            array_filter($this->modules, fn (ModuleManifest $m): bool => $m->isCore()),
        ));
    }

    /**
     * The requested modules PLUS everything they depend on.
     *
     * Enabling "documents" without "orders" would leave delivery notes of
     * nothing. Rather than an error the owner cannot resolve, the dependency is
     * switched on too.
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    public function withDependencies(array $codes): array
    {
        $resolved = [];
        $visiting = [];

        $visit = function (string $code) use (&$visit, &$resolved, &$visiting): void {
            if (in_array($code, $resolved, true)) {
                return;
            }

            if (isset($visiting[$code])) {
                throw new LogicException(
                    "Dependencia circular entre módulos, en «{$code}». ".
                    'Si dos módulos se necesitan mutuamente, o son uno solo, o '.
                    'lo que comparten va en un tercero.'
                );
            }

            $visiting[$code] = true;

            if ($this->has($code)) {
                foreach ($this->get($code)->requires() as $dependency) {
                    $visit($dependency);
                }
            }

            unset($visiting[$code]);
            $resolved[] = $code;
        };

        foreach ($codes as $code) {
            $visit($code);
        }

        return $resolved;
    }

    /**
     * Which enabled modules depend on this one.
     *
     * Stops "orders" being switched off while "kitchen" stays on with nothing
     * to show.
     *
     * @param  list<string>  $active
     * @return list<string>
     */
    public function dependentsOf(string $code, array $active): array
    {
        return array_values(array_filter(
            $active,
            fn (string $other): bool => $other !== $code
                && $this->has($other)
                && in_array($code, $this->get($other)->requires(), true),
        ));
    }

    /**
     * Every permission that exists, given a set of enabled modules.
     *
     * The list a user's permissions are filtered against. A permission of a
     * disabled module never grants access, however the role reads.
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    public function permissionsFor(array $codes): array
    {
        $permissions = [];

        foreach ($codes as $code) {
            if ($this->has($code)) {
                $permissions = [...$permissions, ...$this->get($code)->permissions()];
            }
        }

        return array_values(array_unique($permissions));
    }

    /**
     * The defaults, keyed `module.option`.
     *
     * @param  list<string>  $codes
     * @return array<string, mixed>
     */
    public function defaultSettingsFor(array $codes): array
    {
        $defaults = [];

        foreach ($codes as $code) {
            if (! $this->has($code)) {
                continue;
            }

            foreach ($this->get($code)->settings() as $key => $setting) {
                $defaults["{$code}.{$key}"] = $setting->default;
            }
        }

        return $defaults;
    }

    /** One option's definition, or null if its module no longer exists. */
    public function settingDefinition(string $qualifiedKey): ?Setting
    {
        $parts = explode('.', $qualifiedKey, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$module, $key] = $parts;

        if (! $this->has($module)) {
            return null;
        }

        return $this->get($module)->settings()[$key] ?? null;
    }
}
