<?php

declare(strict_types=1);

namespace Modules\Catalog;

use Illuminate\Support\ServiceProvider;
use Modules\Catalog\Application\Contracts\ModifierCatalog;
use Modules\Catalog\Application\Contracts\ProductCatalog;
use Modules\Catalog\Infrastructure\Services\EloquentModifierCatalog;
use Modules\Catalog\Infrastructure\Services\EloquentProductCatalog;

/**
 * Enlaza el puerto que este módulo publica con su implementación.
 *
 * Quien pida `ProductCatalog` —`Orders`, la caja, el bot— recibe esto y no
 * sabe que detrás hay Eloquent. Es lo que permite que ninguno de ellos importe
 * un modelo de aquí, y hay una prueba de arquitectura que lo verifica.
 */
final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProductCatalog::class, EloquentProductCatalog::class);
        $this->app->bind(ModifierCatalog::class, EloquentModifierCatalog::class);
    }
}
