<?php

declare(strict_types=1);

namespace Platform\Modules;

/**
 * Qué es un módulo.
 *
 * **Módulo ≠ carpeta.** Es una carpeta MÁS este manifiesto, y de aquí sale
 * todo lo demás:
 *
 * - Encender uno es **una fila en `tenant_modules`, sin desplegar nada**.
 * - Sus permisos aparecen y desaparecen del sistema con él: un permiso de un
 *   módulo apagado no existe, aunque el rol lo tenga escrito.
 * - El panel genera su formulario de configuración solo, a partir del tipo de
 *   cada `Setting`.
 * - Sus rutas las carga PlatformServiceProvider bajo el middleware
 *   `module:{code}`; **`routes/api.php` no se toca al añadir un módulo**.
 *
 * Ese conjunto es lo que hace que crecer sea barato. Si algún día añadir una
 * capacidad exige tocar el núcleo, el diseño se rompió y hay que arreglar el
 * diseño, no seguir adelante.
 */
abstract class ModuleManifest
{
    /** Identificador estable. Va en `tenant_modules` y en `module:{code}`. */
    abstract public function code(): string;

    /** Cómo se llama en el menú del dueño. En español, sin jerga. */
    abstract public function name(): string;

    /**
     * En lengua de mostrador y de resultados, no de programador:
     * «Cobrar en el local y entregar una nota», no «Módulo POS».
     */
    public function description(): string
    {
        return '';
    }

    /**
     * De qué otros módulos depende, por código.
     *
     * Por CÓDIGO y no importando su clase: un módulo que nombra la clase de
     * otro ya no se puede borrar sin romper el arranque.
     *
     * @return list<string>
     */
    public function requires(): array
    {
        return [];
    }

    /**
     * Los permisos que este módulo aporta al sistema.
     *
     * Convención para el flujo de autorización por PIN: `orders.void` es
     * ejecutar; `orders.void_request` es iniciar. **No son el mismo permiso y
     * nadie tiene los dos.**
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return [];
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [];
    }

    /** Ruta al archivo de rutas de este módulo, si tiene. */
    public function routes(): ?string
    {
        return null;
    }

    /** Carpeta de migraciones propias, si tiene. */
    public function migrations(): ?string
    {
        return null;
    }

    /**
     * Núcleo: todo negocio lo tiene siempre, no depende del plan y no se puede
     * apagar. Es lo mínimo sin lo cual el sistema no es un sistema.
     */
    public function isCore(): bool
    {
        return false;
    }
}
