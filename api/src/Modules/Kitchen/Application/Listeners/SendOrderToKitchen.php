<?php

declare(strict_types=1);

namespace Modules\Kitchen\Application\Listeners;

use App\Models\Kitchen\KitchenTicketModel;
use Illuminate\Database\DatabaseManager;
use Modules\Orders\Domain\Events\OrderConfirmed;
use Platform\Capabilities\CurrentCapabilities;

/**
 * **Aquí es donde un pedido se convierte en comanda.**
 *
 * Es todo el enganche entre los pedidos y la cocina, y va en una sola
 * dirección: `Orders` no sabe que esto existe. Si mañana se borrara el módulo
 * de cocina, los pedidos seguirían funcionando igual.
 *
 * El evento trae todo lo que hace falta, así que este listener **no consulta
 * las tablas de pedidos**. Si tuviera que hacerlo, el acoplamiento que el
 * evento venía a evitar volvería por la puerta de atrás.
 */
final class SendOrderToKitchen
{
    public function __construct(
        private readonly CurrentCapabilities $capabilities,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(OrderConfirmed $event): void
    {
        /*
         * El listener se registra una vez para el proceso, pero el negocio se
         * resuelve por petición. Sin esta guarda, un negocio que no tenga
         * encendida la cocina —una cocina oculta que sólo despacha, un puesto
         * donde el que atiende es el que cocina— intentaría escribir en una
         * tabla que para él no existe.
         */
        if (! $this->capabilities->get()->hasModule('kitchen')) {
            return;
        }

        $this->db->transaction(function () use ($event): void {
            $ticket = KitchenTicketModel::create([
                'order_id' => $event->orderId,
                // EL MISMO número del pedido. Dos numeraciones distintas para
                // lo mismo es cómo se entrega el plato equivocado.
                'number' => $event->number,
                'status' => 'pending',
                'service_type' => $event->serviceType,
                'notes' => $event->notes,
                'prep_minutes' => $event->prepMinutes,
                'taken_by_name' => $event->confirmedByName,
                'placed_at' => now(),
            ]);

            foreach ($event->lines as $index => $line) {
                $ticket->items()->create([
                    'product_id' => $line->productId,
                    'name' => $line->name,
                    'quantity' => $line->quantity,
                    // Texto ya resuelto. La cocina lee «Sin cebolla» mientras
                    // cocina; ir a buscar un identificador no es una opción.
                    'modifiers' => $line->modifiers,
                    'notes' => $line->notes,
                    'sort_order' => $index,
                ]);
            }
        });
    }
}
