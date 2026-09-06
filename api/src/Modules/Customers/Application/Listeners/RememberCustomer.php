<?php

declare(strict_types=1);

namespace Modules\Customers\Application\Listeners;

use App\Models\Customers\CustomerModel;
use Modules\Orders\Domain\Events\OrderPlaced;
use Platform\Capabilities\CurrentCapabilities;

/**
 * Keeps track of who buys.
 *
 * Updated when the order is PLACED, not delivered: the question is "has this
 * number ordered before?", and a cancelled order answers it too.
 *
 * Synchronous rather than queued — it is a one-row update — and silent when the
 * module is switched off.
 */
final class RememberCustomer
{
    public function __construct(private readonly CurrentCapabilities $capabilities) {}

    public function handle(OrderPlaced $event): void
    {
        if (! $this->capabilities->get()->hasModule('customers')) {
            return;
        }

        $phone = trim((string) $event->customerPhone);

        // No phone number, nobody to remember. That happens at the counter, where
        // most people do not leave one, and that is fine.
        if ($phone === '') {
            return;
        }

        $hash = CustomerModel::hashOf($phone);

        $customer = CustomerModel::where('phone_hash', $hash)->first();

        if ($customer === null) {
            CustomerModel::create([
                'phone' => $phone,
                'phone_hash' => $hash,
                'name' => $event->customerName,
                'orders_count' => 1,
                'spent_cents' => $event->totalCents,
                'last_order_at' => now(),
            ]);

            return;
        }

        $customer->update([
            // The name is updated when one comes in: people spell it better the second
            // time, and an old order's version need not win.
            'name' => $event->customerName ?? $customer->name,
            'orders_count' => $customer->orders_count + 1,
            'spent_cents' => $customer->spent_cents + $event->totalCents,
            'last_order_at' => now(),
        ]);
    }
}
