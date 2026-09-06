<?php

declare(strict_types=1);

namespace Modules\Channels;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Channels\Application\Listeners\NotifyCustomer;
use Modules\Orders\Domain\Events\OrderAdvanced;

/**
 * The channels' entire hook-up: one line.
 *
 * Delete the directory and its `config/modules.php` line and orders carry on —
 * only nobody gets told anything. The same test the kitchen passes.
 */
final class ChannelsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(OrderAdvanced::class, NotifyCustomer::class);
    }
}
