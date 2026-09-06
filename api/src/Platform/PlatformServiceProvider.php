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
 * The engine. It depends on no module, and an architecture test verifies it
 * (`Platform` never imports `Modules`).
 *
 * First in bootstrap/providers.php: everything else leans on the tenant context
 * and the module registry wired up here.
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/kombo.php', 'kombo');

        // EXPLICIT singletons. Resolving the tenant context twice per request
        // would be an intermittent failure and terribly expensive to find.
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
     * A module's routes are declared by ITS manifest, not `routes/api.php`.
     *
     * Always loaded, on or off; the `module:` middleware does the filtering. So
     * switching a module on opens its routes the instant the row is written.
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
     * Re-pins the tenant on every new transaction.
     *
     * Plugs a hole that only shows under concurrency: a pooled connection keeps
     * the previous tenant's parameter, and the next request to take it before
     * the middleware reconfigures it would see somebody else's data.
     */
    private function guardConnectionsOnTransactions(): void
    {
        $guard = $this->app->make(TenantDatabaseGuard::class);

        Event::listen(TransactionBeginning::class, $guard->onTransactionBeginning(...));
    }
}
