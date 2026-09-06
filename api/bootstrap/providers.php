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
 * Order matters. PlatformServiceProvider goes first: it registers the tenant
 * context and the module registry, which everything else depends on.
 *
 * Module providers go in the middle; vertical ones would go LAST, because they
 * replace container bindings and in Laravel the last registration wins.
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
