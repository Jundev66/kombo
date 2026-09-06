<?php

declare(strict_types=1);

namespace Modules\Delivery;

use Illuminate\Support\ServiceProvider;

/**
 * Delivery binds no ports of its own yet: it is zones with their fees, which
 * orders read when computing the total.
 *
 * It exists so adding a module stays "a directory, a provider and one line".
 */
final class DeliveryServiceProvider extends ServiceProvider {}
