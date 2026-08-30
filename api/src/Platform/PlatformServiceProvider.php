<?php

declare(strict_types=1);

namespace Platform;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Platform\Auth\Console\ReconcileRolesCommand;
use Platform\Capabilities\CapabilityResolver;
use Platform\Capabilities\CurrentCapabilities;
use Platform\Modules\ModuleManifest;
use Platform\Modules\ModuleRegistry;
use Platform\Tenancy\Database\TenantDatabaseGuard;
use Platform\Tenancy\TenantContext;
use Platform\Tenancy\TenantResolver;

/**
 * El motor. No depende de ningún módulo, y hay una prueba de arquitectura que
 * lo verifica (`Platform` nunca importa `Modules`).
 *
 * Va PRIMERO en bootstrap/providers.php: todo lo demás se apoya en el contexto
 * de negocio y en el registro de módulos que se montan aquí.
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/kombo.php', 'kombo');

        // Singletons EXPLÍCITOS. Un ciclo de vida accidental no es un ciclo de
        // vida: si el contexto de negocio se resolviera dos veces por petición
        // el fallo sería intermitente y carísimo de encontrar.
        $this->app->singleton(TenantContext::class);

        $this->app->singleton(
            TenantDatabaseGuard::class,
            fn ($app) => new TenantDatabaseGuard($app->make(DatabaseManager::class)),
        );

        $this->app->singleton(TenantResolver::class, fn ($app) => new TenantResolver(
            db: $app->make(DatabaseManager::class)->connection(),
            cache: $app->make(Cache::class),
            rootDomain: (string) config('kombo.root_domain'),
            ttlSeconds: (int) config('kombo.tenant_cache_ttl'),
        ));

        $this->app->singleton(ModuleRegistry::class, function ($app): ModuleRegistry {
            $registry = new ModuleRegistry;

            /** @var list<class-string<ModuleManifest>> $manifests */
            $manifests = config('modules.manifests', []);

            foreach ($manifests as $manifest) {
                $registry->register($app->make($manifest));
            }

            return $registry;
        });

        $this->app->singleton(CapabilityResolver::class, fn ($app) => new CapabilityResolver(
            db: $app->make(DatabaseManager::class),
            cache: $app->make(Cache::class),
            registry: $app->make(ModuleRegistry::class),
        ));

        $this->app->singleton(CurrentCapabilities::class);
    }

    public function boot(): void
    {
        $this->guardConnectionsOnTransactions();
        $this->registerModuleRoutes();
        $this->registerModuleMigrations();

        $this->commands([ReconcileRolesCommand::class]);
    }

    /**
     * Las rutas de un módulo las declara SU MANIFIESTO, no `routes/api.php`.
     *
     * Se cargan SIEMPRE, esté el módulo encendido o no; el filtrado lo hace el
     * middleware `module:`. Así, encender la caja abre sus rutas en el instante
     * en que se escribe la fila, sin reiniciar ni desplegar nada.
     */
    private function registerModuleRoutes(): void
    {
        foreach ($this->app->make(ModuleRegistry::class)->all() as $manifest) {
            $routes = $manifest->routes();

            if ($routes !== null && is_file($routes)) {
                $this->loadRoutesFrom($routes);
            }
        }
    }

    private function registerModuleMigrations(): void
    {
        foreach ($this->app->make(ModuleRegistry::class)->all() as $manifest) {
            $migrations = $manifest->migrations();

            if ($migrations !== null && is_dir($migrations)) {
                $this->loadMigrationsFrom($migrations);
            }
        }
    }

    /**
     * Vuelve a fijar el negocio en cada transacción nueva.
     *
     * Tapa un agujero que sólo aparece bajo concurrencia: una conexión
     * devuelta al pool conserva el parámetro del negocio anterior, y la
     * siguiente petición que la tome antes de que el middleware la
     * reconfigure vería datos ajenos.
     */
    private function guardConnectionsOnTransactions(): void
    {
        $guard = $this->app->make(TenantDatabaseGuard::class);

        Event::listen(TransactionBeginning::class, $guard->onTransactionBeginning(...));
    }
}
