<?php

declare(strict_types=1);

namespace Modules\Kitchen\Application\Listeners;

use App\Models\Kitchen\KitchenTicketModel;
use Modules\Kitchen\Domain\ValueObjects\TicketStatus;
use Modules\Orders\Domain\Events\OrderCancelled;
use Platform\Capabilities\CurrentCapabilities;

/**
 * Sacar del tablero lo que ya no hay que cocinar.
 *
 * La comanda **no se borra**: queda con estado `cancelled` y su hora. Que la
 * comida se haya empezado a hacer es un hecho, y borrarlo dejaría al dueño sin
 * saber cuánta materia prima se perdió en el mes.
 *
 * Lo que sí pasa es que desaparece de la pantalla en el siguiente sondeo —
 * cinco segundos— para que nadie siga con ella.
 */
final class CancelKitchenTicket
{
    public function __construct(
        private readonly CurrentCapabilities $capabilities,
    ) {}

    public function handle(OrderCancelled $event): void
    {
        if (! $this->capabilities->get()->hasModule('kitchen')) {
            return;
        }

        $ticket = KitchenTicketModel::where('order_id', $event->orderId)->first();

        // Puede no haber comanda: un pedido cancelado antes de confirmarse
        // nunca llegó a la cocina.
        if ($ticket === null) {
            return;
        }

        // Si ya salió de la cocina, la comida está hecha y entregada: cancelar
        // el cobro no la devuelve a la plancha.
        if ($ticket->status === TicketStatus::Served || $ticket->status === TicketStatus::Cancelled) {
            return;
        }

        $ticket->update([
            'status' => TicketStatus::Cancelled->value,
            'cancelled_at' => now(),
        ]);
    }
}
