<?php

declare(strict_types=1);

namespace Modules\Customers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Customers\Application\Listeners\RememberCustomer;
use Modules\Orders\Domain\Events\OrderPlaced;

/**
 * Todo el enganche: una línea.
 *
 * `Orders` no sabe que este módulo existe. Se puede borrar la carpeta entera y
 * los pedidos siguen igual — sólo que nadie lleva la cuenta de quién compra.
 */
final class CustomersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(OrderPlaced::class, RememberCustomer::class);
    }
}
