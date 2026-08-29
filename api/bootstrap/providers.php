<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Modules\Catalog\CatalogServiceProvider;
use Modules\Channels\ChannelsServiceProvider;
use Modules\Counter\CounterServiceProvider;
use Modules\Customers\CustomersServiceProvider;
use Modules\Delivery\DeliveryServiceProvider;
use Modules\Documents\DocumentsServiceProvider;
use Modules\Kitchen\KitchenServiceProvider;
use Modules\Portal\PortalServiceProvider;
use Modules\Reports\ReportsServiceProvider;
use Platform\PlatformServiceProvider;
use Platform\Subscription\SubscriptionServiceProvider;

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
    SubscriptionServiceProvider::class,

    CatalogServiceProvider::class,
    KitchenServiceProvider::class,
    DocumentsServiceProvider::class,
    CounterServiceProvider::class,
    DeliveryServiceProvider::class,
    PortalServiceProvider::class,
    ChannelsServiceProvider::class,
    ReportsServiceProvider::class,
    CustomersServiceProvider::class,

    AppServiceProvider::class,
];
