<?php

declare(strict_types=1);

namespace Modules\Customers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Customers\Application\Listeners\RememberCustomer;
use Modules\Orders\Domain\Events\OrderPlaced;

/**
 * The whole hook-up: one line.
 *
 * `Orders` does not know this module exists. Delete the directory and orders
 * carry on — only nobody keeps track of who buys.
 */
final class CustomersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(OrderPlaced::class, RememberCustomer::class);
    }
}
