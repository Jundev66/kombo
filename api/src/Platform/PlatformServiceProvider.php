<?php

declare(strict_types=1);

namespace Platform;

use Illuminate\Support\ServiceProvider;

/**
 * El motor. No depende de ningún módulo, y hay una prueba de arquitectura que
 * lo verifica (`Platform` nunca importa `Modules`).
 *
 * Va PRIMERO en bootstrap/providers.php: todo lo demás se apoya en el registro
 * de módulos y en el contexto de negocio que se montan aquí.
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/kombo.php', 'kombo');

        // Fase 1: TenantContext, TenantResolver, TenantDatabaseGuard,
        // ModuleRegistry, CapabilityResolver y CurrentCapabilities como
        // singletons explícitos. Un ciclo de vida accidental no es un ciclo de
        // vida: si el contexto de negocio se resolviera dos veces por petición,
        // el fallo sería intermitente y carísimo de encontrar.
    }

    public function boot(): void
    {
        // Fase 1: el listener de TransactionBeginning que vuelve a fijar el
        // negocio con alcance local al abrir cada transacción, y la carga de
        // rutas y migraciones declaradas por los manifiestos de los módulos.
    }
}
