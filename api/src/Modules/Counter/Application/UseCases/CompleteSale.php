<?php

declare(strict_types=1);

namespace Modules\Counter\Application\UseCases;

use App\Models\Documents\DeliveryNoteModel;
use App\Models\Orders\OrderModel;
use Illuminate\Database\DatabaseManager;
use Modules\Documents\Application\UseCases\IssueDeliveryNote;
use Modules\Orders\Application\UseCases\AdvanceOrder;
use Modules\Orders\Application\UseCases\PlaceOrder;
use Modules\Orders\Application\UseCases\RegisterPayment;
use Modules\Orders\Domain\ValueObjects\OrderStatus;
use Modules\Orders\Domain\ValueObjects\ServiceType;
use Platform\Audit\AuditLogger;

/**
 * Una venta de mostrador, de principio a fin.
 *
 * El cliente está delante y ya pagó, así que aquí no hay nada que esperar: el
 * pedido nace, se confirma —y con eso **entra directo a la cocina**—, se
 * registran los pagos y se emite la nota. Todo en una transacción: media venta
 * guardada es peor que ninguna.
 *
 * Lo que este caso de uso NO hace, a propósito: abrir turno, cerrar caja ni
 * cuadrar el efectivo. Eso es otra fase y otra conversación.
 */
final class CompleteSale
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PlaceOrder $placeOrder,
        private readonly AdvanceOrder $advanceOrder,
        private readonly RegisterPayment $payments,
        private readonly IssueDeliveryNote $notes,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{product_id: string, quantity: int, modifier_ids?: list<string>, notes?: string|null}>  $items
     * @param  list<array{method: string, amount_cents: int, reference?: string|null}>  $payments
     * @return array{order: OrderModel, note: DeliveryNoteModel}
     */
    public function execute(
        array $items,
        array $payments,
        ServiceType $serviceType = ServiceType::Takeaway,
        ?string $customerName = null,
        ?string $customerTaxId = null,
        ?string $notes = null,
    ): array {
        return $this->db->transaction(function () use (
            $items, $payments, $serviceType, $customerName, $customerTaxId, $notes
        ): array {
            // Los precios salen del catálogo. La caja manda qué y cuántos.
            $order = $this->placeOrder->execute(
                items: $items,
                serviceType: $serviceType,
                channel: 'counter',
                customerName: $customerName,
                notes: $notes,
            );

            // Confirmar es lo que manda la comanda a la cocina. En el mostrador
            // no hay nada que revisar antes: el cliente está ahí y ya pagó.
            $order = $this->advanceOrder->execute($order->id, OrderStatus::Confirmed);

            foreach ($payments as $payment) {
                $order = $this->payments->execute(
                    orderId: (string) $order->id,
                    method: $payment['method'],
                    amountCents: $payment['amount_cents'],
                    reference: $payment['reference'] ?? null,
                    // El cajero mira la notificación del pago móvil en su
                    // teléfono antes de entregar la comida. Dejarlo esperando
                    // revisión imprimiría una nota diciendo que el cliente aún
                    // debe, con el cliente ya saliendo por la puerta.
                    verifiedInPerson: true,
                );
            }

            $note = $this->notes->execute($order, $customerName, $customerTaxId);

            $this->audit->record(
                action: 'counter.sale_completed',
                entityType: 'order',
                entityId: (string) $order->id,
                after: [
                    'number' => $order->number,
                    'total_cents' => $order->total_cents,
                    'note' => $note->reference(),
                ],
            );

            return ['order' => $order->refresh(), 'note' => $note];
        });
    }
}
