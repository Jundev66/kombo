<?php

declare(strict_types=1);

use Modules\Catalog\CatalogModule;
use Modules\Channels\ChannelsModule;
use Modules\Core\CoreModule;
use Modules\Counter\CounterModule;
use Modules\Customers\CustomersModule;
use Modules\Delivery\DeliveryModule;
use Modules\Documents\DocumentsModule;
use Modules\Kitchen\KitchenModule;
use Modules\Orders\OrdersModule;
use Modules\Portal\PortalModule;
use Modules\Reports\ReportsModule;

return [
    /*
     * The modules that EXIST in this version of the system. Whether a tenant
     * has them is what its plan and `tenant_modules` say.
     *
     * DECLARED here rather than discovered by walking directories: what exists
     * should not depend on which files survived a half-finished deployment.
     *
     * Adding a module is its directory with the four layers, its provider in
     * bootstrap/providers.php, and one line here. `routes/api.php` is untouched.
     */
    'manifests' => [
        // Core. Always on, independent of the plan, cannot be switched off.
        CoreModule::class,
        CatalogModule::class,
        OrdersModule::class,

        // Optional: switched on if the tenant needs them.
        KitchenModule::class,
        DocumentsModule::class,
        CounterModule::class,
        DeliveryModule::class,
        PortalModule::class,
        ChannelsModule::class,
        ReportsModule::class,
        CustomersModule::class,

    ],
];
