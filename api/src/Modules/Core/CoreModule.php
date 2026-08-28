<?php

declare(strict_types=1);

namespace Modules\Core;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * El núcleo. Todo negocio lo tiene, no depende del plan y no se puede apagar.
 *
 * Es lo mínimo sin lo cual el sistema no es un sistema: quién trabaja aquí,
 * qué puede hacer cada uno, y cómo se llama el negocio.
 */
final class CoreModule extends ModuleManifest
{
    public function code(): string
    {
        return 'core';
    }

    public function name(): string
    {
        return 'Configuración';
    }

    public function description(): string
    {
        return 'Los datos del negocio, quién trabaja aquí y qué puede hacer cada uno.';
    }

    public function isCore(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return [
            'settings.manage',
            'users.manage',
            'audit.view',
        ];
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [
            // Cuántos dígitos tiene el PIN. Cuatro es lo que la gente recuerda
            // y lo que se teclea rápido con un cliente delante; seis es más
            // seguro y algunos dueños lo prefieren. El valor por defecto es el
            // comportamiento de hoy, así que nadie nota que existe la opción.
            'pin_length' => Setting::int(4)->min(4)->max(8),

            // Cuántos intentos fallidos antes de bloquear un minuto. Un PIN de
            // cuatro dígitos son 10.000 combinaciones: sin este tope, probarlas
            // todas es cuestión de un rato.
            'pin_attempts' => Setting::int(5)->min(3)->max(10),
        ];
    }
}
