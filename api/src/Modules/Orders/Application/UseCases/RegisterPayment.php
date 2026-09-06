<?php

declare(strict_types=1);

namespace Modules\Orders\Application\UseCases;

use App\Models\Orders\OrderModel;
use App\Models\Orders\OrderPaymentModel;
use Illuminate\Database\DatabaseManager;
use Modules\Orders\Application\Exceptions\OrderNotFound;
use Modules\Orders\Domain\ValueObjects\OrderStatus;
use Platform\Audit\AuditLogger;

/**
 * Recording a payment against an order. There can be SEVERAL, and that is the
 * point: people pay in a mix — some cash, the rest by mobile transfer.
 *
 * Mobile payment is confirmed by hand: there is no reliable banking API to ask,
 * and pretending there is would be worse than owning it.
 */
final class RegisterPayment
{
    /** Those taken as good on the spot: the money is in your hand. */
    private const IMMEDIATE = ['cash_usd', 'cash_bs', 'card'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  bool  $verifiedInPerson  Taken as good by whoever is charging. At
     *                                  the counter the cashier checks the
     *                                  notification before handing over the
     *                                  food; in the portal the customer uploads
     *                                  a receipt, so the default is no.
     */
    public function execute(
        string $orderId,
        string $method,
        int $amountCents,
        ?string $reference = null,
        ?string $receiptUrl = null,
        bool $verifiedInPerson = false,
    ): OrderModel {
        $order = OrderModel::find($orderId) ?? throw new OrderNotFound;

        return $this->db->transaction(function () use ($order, $method, $amountCents, $reference, $receiptUrl, $verifiedInPerson): OrderModel {
            $confirmedNow = $verifiedInPerson || in_array($method, self::IMMEDIATE, true);

            $order->payments()->create([
                'method' => $method,
                'amount_cents' => $amountCents,
                'currency' => $order->currency,
                // THIS payment's rate: paying in two goes across a rate change means each
                // payment is worth what it was worth.
                'exchange_rate' => $order->exchange_rate,
                'reference' => $reference,
                'receipt_url' => $receiptUrl,
                'status' => $confirmedNow ? 'confirmed' : 'pending_review',
                'confirmed_by' => $confirmedNow ? auth()->id() : null,
                'confirmed_at' => $confirmedNow ? now() : null,
                'created_by' => auth()->id(),
            ]);

            $this->recalculate($order);

            $this->audit->record(
                action: 'orders.payment_registered',
                entityType: 'order',
                entityId: (string) $order->id,
                after: ['method' => $method, 'amount_cents' => $amountCents],
            );

            return $order->refresh();
        });
    }

    /** Taking a payment that was pending review as good. */
    public function confirm(string $paymentId): OrderModel
    {
        $payment = OrderPaymentModel::find($paymentId) ?? throw new OrderNotFound;

        $payment->update([
            'status' => 'confirmed',
            'confirmed_by' => auth()->id(),
            'confirmed_at' => now(),
        ]);

        $order = OrderModel::findOrFail($payment->order_id);

        $this->recalculate($order);

        $this->audit->record(
            action: 'orders.payment_confirmed',
            entityType: 'order',
            entityId: (string) $order->id,
            after: ['payment_id' => $paymentId, 'amount_cents' => $payment->amount_cents],
        );

        return $order->refresh();
    }

    /**
     * Recomputes what has been paid from the CONFIRMED payments.
     *
     * Recomputed rather than accumulated: two fields that ought to agree end up
     * disagreeing, and the one being looked at is always the wrong one.
     */
    private function recalculate(OrderModel $order): void
    {
        $paid = (int) $order->payments()->where('status', 'confirmed')->sum('amount_cents');

        $order->paid_cents = $paid;
        $order->payment_status = match (true) {
            $paid <= 0 => 'unpaid',
            $paid < (int) $order->total_cents => 'partial',
            default => 'paid',
        };

        if ($order->status === OrderStatus::PendingPayment && $order->payment_status === 'paid') {
            $order->status = OrderStatus::Placed;
            $order->state_version = (int) $order->state_version + 1;
        }

        $order->save();
    }
}
