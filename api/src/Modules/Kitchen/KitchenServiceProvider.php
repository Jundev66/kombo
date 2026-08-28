<?php

declare(strict_types=1);

namespace Modules\Kitchen;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Kitchen\Application\Listeners\CancelKitchenTicket;
use Modules\Kitchen\Application\Listeners\SendOrderToKitchen;
use Modules\Orders\Domain\Events\OrderCancelled;
use Modules\Orders\Domain\Events\OrderConfirmed;

/**
 * Todo el enganche de la cocina al resto del sistema: una línea.
 *
 * `Orders` no sabe que este módulo existe. Se puede borrar la carpeta entera y
 * su línea de `config/modules.php`, y los pedidos siguen funcionando — sólo
 * que ya no van a ninguna pantalla de cocina. Ésa es la prueba de que el
 * diseño aguanta.
 */
final class KitchenServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(OrderConfirmed::class, SendOrderToKitchen::class);
        Event::listen(OrderCancelled::class, CancelKitchenTicket::class);
    }
}
