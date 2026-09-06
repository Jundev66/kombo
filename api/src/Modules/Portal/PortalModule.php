<?php

declare(strict_types=1);

namespace Modules\Portal;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * The ordering portal: the tenant's public face, and the only part of the
 * system used without a session.
 *
 * The customer arrives at the tenant's subdomain, looks at the menu, orders and
 * follows it with a link. Switched off wholesale for a stall that only sells
 * over the counter.
 */
final class PortalModule extends ModuleManifest
{
    public function code(): string
    {
        return 'portal';
    }

    public function name(): string
    {
        return 'Portal de pedidos';
    }

    public function description(): string
    {
        return 'La carta en línea, para que el cliente pida desde su teléfono.';
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return ['catalog', 'orders'];
    }

    public function routes(): ?string
    {
        return __DIR__.'/Interfaces/Http/Routes/api.php';
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [
            // How the order can be received. Delivery also needs the delivery module
            // on: with no zones there is nowhere to take it.
            'accepts_takeaway' => Setting::bool(true),
            'accepts_delivery' => Setting::bool(true),

            /*
             * How it is paid for. Cash on delivery needs nothing else; mobile
             * payment needs somewhere to send the money, so the portal does not
             * offer it when those details are empty — a pay button that does
             * not say who to pay is a guaranteed phone call.
             */
            'accepts_cash' => Setting::bool(true),
            'accepts_pago_movil' => Setting::bool(true),
            'pago_movil_details' => Setting::text('')->maxLength(300),

            /*
             * How long whoever went off to pay is waited for. Two hours: how
             * long it takes to go to the bank, not to have second thoughts.
             */
            'payment_window_minutes' => Setting::int(120)->min(10)->max(1440),

            // A short message at the very top: "no chicken today", "closed on the
            // 24th". Empty, it is not shown.
            'notice' => Setting::text('')->maxLength(200),
        ];
    }
}
