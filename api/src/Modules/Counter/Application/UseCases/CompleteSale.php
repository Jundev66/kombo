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
 * A counter sale, start to finish.
 *
 * The customer is standing there and has paid, so nothing waits: the order is
 * created, confirmed (which sends it to the kitchen), the payments recorded and
 * the note issued — all in one transaction, because half a sale saved is worse
 * than none.
 *
 * It deliberately does not open shifts, close the till or reconcile cash.
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
            // Prices come from the catalog. The till says what and how many.
            $order = $this->placeOrder->execute(
                items: $items,
                serviceType: $serviceType,
                channel: 'counter',
                customerName: $customerName,
                notes: $notes,
            );

            // Confirming is what sends the ticket to the kitchen. At the counter there
            // is nothing to review first.
            $order = $this->advanceOrder->execute($order->id, OrderStatus::Confirmed);

            foreach ($payments as $payment) {
                $order = $this->payments->execute(
                    orderId: (string) $order->id,
                    method: $payment['method'],
                    amountCents: $payment['amount_cents'],
                    reference: $payment['reference'] ?? null,
                    // The cashier checks the mobile-payment notification before handing over
                    // the food. Leaving it pending would print a note saying the customer
                    // still owes money, with them already walking out the door.
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
