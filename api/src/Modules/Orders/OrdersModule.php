<?php

declare(strict_types=1);

namespace Modules\Orders;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * The orders: what is sold, where it has got to and how it is paid for.
 *
 * Core, like the menu. The kitchen, the till and the portal all hang off it.
 */
final class OrdersModule extends ModuleManifest
{
    public function code(): string
    {
        return 'orders';
    }

    public function name(): string
    {
        return 'Pedidos';
    }

    public function description(): string
    {
        return 'Lo que te piden, por dónde va cada uno y cuánto han pagado.';
    }

    public function isCore(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return ['catalog'];
    }

    public function routes(): ?string
    {
        return __DIR__.'/Interfaces/Http/Routes/api.php';
    }

    public function migrations(): ?string
    {
        return __DIR__.'/Infrastructure/Migrations';
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return [
            'orders.view',
            'orders.create',
            'orders.confirm',
            'orders.advance',

            // Cancelling is separate from confirming and has its `_request`
            // counterpart: it is the natural way to get food out unpaid, so the
            // counter starts it and the manager authorises it.
            'orders.cancel',
            'orders.cancel_request',

            'payments.confirm',
        ];
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [
            /*
             * Auto-confirming incoming orders. A shop with its own kitchen
             * wants to look first; a small one would rather they landed
             * straight through. The default is today's behaviour, so switching
             * it on is a decision rather than a surprise.
             */
            'auto_confirm' => Setting::bool(false),

            // From how many unconfirmed minutes it gets flagged on the board. An order
            // forgotten for twenty minutes is a lost customer.
            'unconfirmed_alert_minutes' => Setting::int(5)->min(1)->max(60),
        ];
    }
}
