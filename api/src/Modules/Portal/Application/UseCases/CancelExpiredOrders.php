<?php

declare(strict_types=1);

namespace Modules\Portal\Application\UseCases;

use App\Models\Orders\OrderModel;
use Modules\Orders\Application\UseCases\CancelOrder;
use Modules\Orders\Domain\ValueObjects\OrderStatus;

/**
 * Cerrar los pedidos a los que se les acabó el tiempo de pagar.
 *
 * El cliente se fue a la aplicación del banco y no volvió: cambió de idea, no
 * le alcanzó, o sencillamente se le olvidó. Pasa todos los días y no es un
 * fallo de nadie.
 *
 * Lo que sí sería un fallo es dejarlos ahí. Un tablero con la mitad de los
 * pedidos esperando un pago que nunca llegó es un tablero que el negocio deja
 * de mirar, y ese día se le pasa uno de verdad.
 *
 * Se cancela **uno a uno por el caso de uso normal**, no con un `update`
 * masivo: así cada cancelación pasa por la máquina de estados, queda en la
 * bitácora con su motivo, y avisa a quien tenga que enterarse.
 */
final class CancelExpiredOrders
{
    public function __construct(private readonly CancelOrder $cancelOrder) {}

    /** @return int cuántos se cerraron */
    public function execute(): int
    {
        $expired = OrderModel::query()
            ->where('status', OrderStatus::PendingPayment->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            // De cien en cien: si un negocio acumuló mil, es mejor cerrarlos en
            // varias pasadas que tener una tarea que tarda un minuto entero.
            ->limit(100)
            ->get();

        foreach ($expired as $order) {
            $this->cancelOrder->execute(
                (string) $order->id,
                'Se venció el plazo para enviar el comprobante del pago.',
            );
        }

        return $expired->count();
    }
}
