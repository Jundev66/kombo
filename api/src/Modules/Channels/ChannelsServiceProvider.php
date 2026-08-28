<?php

declare(strict_types=1);

namespace Modules\Channels;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Channels\Application\Listeners\NotifyCustomer;
use Modules\Orders\Domain\Events\OrderAdvanced;

/**
 * Todo el enganche de los canales: una línea.
 *
 * `Orders` no sabe que este módulo existe. Se puede borrar la carpeta entera y
 * su línea de `config/modules.php`, y los pedidos siguen funcionando — sólo que
 * ya no avisa nadie. Es la misma prueba que pasa la cocina.
 */
final class ChannelsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(OrderAdvanced::class, NotifyCustomer::class);
    }
}
