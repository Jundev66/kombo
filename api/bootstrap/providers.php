<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Modules\Catalog\CatalogServiceProvider;
use Platform\PlatformServiceProvider;

/*
 * El orden importa.
 *
 * PlatformServiceProvider va primero: registra el contexto de negocio y el
 * registro de módulos, y todo lo demás depende de que existan.
 *
 * Los proveedores de MÓDULO van en medio, y cuando llegue el momento los
 * verticales van AL FINAL: sustituyen enlaces del contenedor (un puerto que el
 * núcleo declara con una implementación neutra), y en Laravel gana el último
 * que registra. Es lo que permite que un vertical extienda el núcleo sin que
 * el núcleo sepa que existe.
 */
return [
    PlatformServiceProvider::class,

    CatalogServiceProvider::class,

    AppServiceProvider::class,
];
