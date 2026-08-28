<?php

declare(strict_types=1);

use Modules\Catalog\CatalogModule;
use Modules\Channels\ChannelsModule;
use Modules\Core\CoreModule;
use Modules\Counter\CounterModule;
use Modules\Delivery\DeliveryModule;
use Modules\Documents\DocumentsModule;
use Modules\Kitchen\KitchenModule;
use Modules\Orders\OrdersModule;
use Modules\Portal\PortalModule;
use Modules\Reports\ReportsModule;

return [
    /*
     * Los módulos que EXISTEN en esta versión del sistema.
     *
     * Que un negocio los tenga o no es otra cosa —eso lo dicen su plan y
     * `tenant_modules`—. Esta lista es sólo el catálogo de lo que hay.
     *
     * Se DECLARAN aquí y no se descubren recorriendo carpetas: qué módulos
     * existen no debería depender de qué archivos quedaron en el disco tras un
     * despliegue a medias.
     *
     * Añadir un módulo es: su carpeta con las cuatro capas, su proveedor en
     * bootstrap/providers.php, y una línea aquí. `routes/api.php` no se toca.
     */
    'manifests' => [
        // Núcleo. Siempre encendido, no depende del plan, no se apaga.
        CoreModule::class,
        CatalogModule::class,
        OrdersModule::class,

        // Opcionales: se encienden si el negocio los necesita.
        KitchenModule::class,
        DocumentsModule::class,
        CounterModule::class,
        DeliveryModule::class,
        PortalModule::class,
        ChannelsModule::class,
        ReportsModule::class,

    ],
];
