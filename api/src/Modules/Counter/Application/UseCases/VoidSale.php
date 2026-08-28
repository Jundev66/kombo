<?php

declare(strict_types=1);

namespace Modules\Counter\Application\UseCases;

use App\Models\Documents\DeliveryNoteModel;
use App\Models\Orders\OrderModel;
use Illuminate\Database\DatabaseManager;
use Modules\Documents\Application\UseCases\IssueDeliveryNote;
use Modules\Orders\Application\UseCases\CancelOrder;
use Platform\Audit\AuthorizedBy;

/**
 * Anular una venta de mostrador.
 *
 * **Es una sola operación, no dos.** Anular la nota y dejar el pedido vivo
 * dejaría una venta cobrada sin papel; cancelar el pedido y dejar la nota
 * dejaría un papel que respalda algo que ya no existe. Y como la base sólo
 * admite una nota por pedido, tampoco hay forma de volver a emitirla: anular
 * el documento es, necesariamente, anular la venta.
 *
 * Lo que NO hace: devolver el dinero. Los pagos quedan registrados como lo que
 * fueron, y lo que se le devuelve al cliente en la mano no lo sabe el sistema.
 * Fingir un reverso automático sería inventarse un movimiento de caja que aquí
 * no se lleva.
 */
final class VoidSale
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CancelOrder $cancelOrder,
        private readonly IssueDeliveryNote $notes,
    ) {}

    /**
     * @return array{order: OrderModel, note: DeliveryNoteModel|null}
     */
    public function execute(string $orderId, string $reason, ?AuthorizedBy $authorizedBy = null): array
    {
        return $this->db->transaction(function () use ($orderId, $reason, $authorizedBy): array {
            // Se busca ANTES de cancelar: después de anular el pedido, quien
            // mire esta nota tiene que poder ver por qué.
            $note = DeliveryNoteModel::where('order_id', $orderId)->first();

            // Cancela primero: si el pedido ya está entregado, el dominio lo
            // impide y la nota no llega a tocarse.
            $order = $this->cancelOrder->execute($orderId, $reason, $authorizedBy);

            if ($note !== null) {
                // No libera el número. La siguiente venta toma el siguiente.
                $note = $this->notes->void((string) $note->id, $reason);
            }

            return ['order' => $order, 'note' => $note];
        });
    }
}
