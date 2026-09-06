<?php

declare(strict_types=1);

namespace Modules\Counter;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * The counter till.
 *
 * Not core: a ghost kitchen or a home venture sells only through the portal or
 * WhatsApp, and for them this screen does not exist.
 *
 * It sells and takes payment. It does not open shifts, close the till or
 * reconcile cash — that is another phase.
 */
final class CounterModule extends ModuleManifest
{
    public function code(): string
    {
        return 'counter';
    }

    public function name(): string
    {
        return 'Caja';
    }

    public function description(): string
    {
        return 'Vender y cobrar en el local, y entregar la nota.';
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        // Without the note there is no charging at the counter: the customer has to
        // be handed a piece of paper.
        return ['orders', 'documents'];
    }

    public function routes(): ?string
    {
        return __DIR__.'/Interfaces/Http/Routes/api.php';
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return [
            'counter.sell',

            /*
             * Voiding a sale and discounting are the two natural ways to get
             * goods or money out with no trace, so each has its `_request`
             * counterpart: the counter starts it, the manager authorises it.
             *
             * Voiding a sale is voiding its note, so one permission covers both.
             */
            'counter.void',
            'counter.void_request',
            'counter.discount',
            'counter.discount_request',
        ];
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [
            /*
             * How products are picked. In food you tap the photo; the search
             * box is for whoever has three hundred products.
             */
            'layout' => Setting::enum(['grid', 'search'], 'grid'),

            // How it is handed over by default. At a fast-food counter almost
            // everything is takeaway.
            'default_service_type' => Setting::enum(['takeaway', 'dine_in', 'delivery'], 'takeaway'),
        ];
    }
}
