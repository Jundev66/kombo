<?php

declare(strict_types=1);

namespace Modules\Core;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * The core. Every tenant has it, it does not depend on the plan and it cannot
 * be switched off: who works here, what each of them can do, and the tenant's
 * own details.
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

    public function routes(): ?string
    {
        return __DIR__.'/Interfaces/Http/Routes/api.php';
    }

    public function migrations(): ?string
    {
        return __DIR__.'/Infrastructure/Migrations';
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
            // PIN length. Four is what people remember and type fast with a customer
            // waiting; six is safer and some owners prefer it.
            'pin_length' => Setting::int(4)->min(4)->max(8),

            // Failed attempts before a one-minute lock. Four digits is 10,000
            // combinations: without a cap, trying them all takes minutes.
            'pin_attempts' => Setting::int(5)->min(3)->max(10),
        ];
    }
}
