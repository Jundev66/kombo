<?php

declare(strict_types=1);

namespace Modules\Delivery;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * Home delivery.
 *
 * Switched off wholesale for a street stall or an arepera where everybody
 * collects: for them "delivery" appears nowhere, rather than appearing and not
 * working.
 */
final class DeliveryModule extends ModuleManifest
{
    public function code(): string
    {
        return 'delivery';
    }

    public function name(): string
    {
        return 'Reparto';
    }

    public function description(): string
    {
        return 'Llevar el pedido a casa del cliente, por zonas y con su tarifa.';
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
            'delivery.manage',

            // The courier: their own deliveries and nothing else.
            'delivery.view_own',
            'delivery.mark_delivered',
        ];
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [
            /*
             * The minimum for a trip to be worth making. Zero is "no minimum".
             *
             * A misunderstood minimum leaves the customer with a full basket
             * unable to order, so the portal says it from the first screen.
             */
            'minimum_order_cents' => Setting::money(0),

            // How long it takes when the zone does not say. What the customer is
            // promised before they order.
            'default_minutes' => Setting::int(45)->min(5),
        ];
    }
}
