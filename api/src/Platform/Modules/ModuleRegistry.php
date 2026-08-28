<?php

declare(strict_types=1);

namespace Platform\Modules;

use LogicException;
use Platform\Modules\Exceptions\UnknownModule;

/**
 * Qué módulos EXISTEN en esta versión del sistema.
 *
 * Que un negocio los tenga o no es otra cosa —eso lo dicen su plan y
 * `tenant_modules`—. Esto es sólo el catálogo de lo que hay.
 *
 * Se declara en `config/modules.php` y no se descubre recorriendo carpetas: qué
 * módulos existen no debería depender de qué archivos quedaron en el disco tras
 * un despliegue a medias.
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
     * Los módulos pedidos MÁS todo aquello de lo que dependen.
     *
     * Encender «documentos» sin «pedidos» dejaría notas de entrega de nada. En
     * vez de rechazarlo con un error que el dueño no puede resolver, se
     * enciende lo que hace falta.
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
     * Quién depende de este módulo, entre los que el negocio tiene encendidos.
     *
     * Es lo que impide apagar «pedidos» dejando «cocina» encendida y sin nada
     * que mostrar.
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
     * Todos los permisos que existen, dados unos módulos encendidos.
     *
     * Ésta es la lista contra la que se filtran los permisos de un usuario. Un
     * permiso de un módulo apagado **nunca** concede acceso, aunque su rol lo
     * tenga escrito: el módulo se apagó, el permiso dejó de existir.
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
     * Los valores por defecto, con la clave calificada `modulo.opcion`.
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

    /** La definición de una opción, o null si su módulo ya no existe. */
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
