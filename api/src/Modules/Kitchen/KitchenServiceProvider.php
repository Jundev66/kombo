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
 * The kitchen's entire hook-up to the rest of the system: one line.
 *
 * Delete the directory and its `config/modules.php` line and orders carry on
 * working — they just reach no kitchen screen. That is the proof the design
 * holds.
 */
final class KitchenServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(OrderConfirmed::class, SendOrderToKitchen::class);
        Event::listen(OrderCancelled::class, CancelKitchenTicket::class);
    }
}
