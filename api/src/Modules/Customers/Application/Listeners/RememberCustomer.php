<?php

declare(strict_types=1);

namespace Modules\Customers\Application\Listeners;

use App\Models\Customers\CustomerModel;
use Modules\Orders\Domain\Events\OrderPlaced;
use Platform\Capabilities\CurrentCapabilities;

/**
 * Lleva la cuenta de quién compra.
 *
 * Se actualiza **al hacer el pedido**, no al entregarlo: lo que esta ficha
 * contesta es «¿este número ya pidió antes?», y para eso da igual cómo acabe.
 * Un pedido cancelado también dice que esa persona existe y qué le gusta.
 *
 * Va SÍNCRONO y no en la cola, al revés que los avisos: es un `update` de una
 * fila, y encolarlo costaría más que hacerlo. Lo que sí hace es callarse si el
 * módulo está apagado.
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

        // Sin teléfono no hay a quién recordar. Pasa en el mostrador, donde la
        // mayoría de la gente no lo deja — y está bien.
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
            // El nombre se actualiza si viene uno: la gente lo escribe mejor la
            // segunda vez, y el de un pedido viejo no tiene por qué mandar.
            'name' => $event->customerName ?? $customer->name,
            'orders_count' => $customer->orders_count + 1,
            'spent_cents' => $customer->spent_cents + $event->totalCents,
            'last_order_at' => now(),
        ]);
    }
}
