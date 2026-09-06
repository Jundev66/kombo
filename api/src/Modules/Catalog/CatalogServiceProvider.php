<?php

declare(strict_types=1);

namespace Modules\Catalog;

use Illuminate\Support\ServiceProvider;
use Modules\Catalog\Application\Contracts\ModifierCatalog;
use Modules\Catalog\Application\Contracts\ProductCatalog;
use Modules\Catalog\Infrastructure\Services\EloquentModifierCatalog;
use Modules\Catalog\Infrastructure\Services\EloquentProductCatalog;

/**
 * Binds the published port to its implementation, so nothing outside imports a
 * model from here. An architecture test verifies it.
 */
final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProductCatalog::class, EloquentProductCatalog::class);
        $this->app->bind(ModifierCatalog::class, EloquentModifierCatalog::class);
    }
}
