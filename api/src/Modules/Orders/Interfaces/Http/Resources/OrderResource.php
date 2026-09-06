<?php

declare(strict_types=1);

namespace Modules\Orders\Interfaces\Http\Resources;

use App\Models\Orders\OrderItemModel;
use App\Models\Orders\OrderModel;
use App\Models\Orders\OrderPaymentModel;

/**
 * How an order looks to the client: camelCase, always in cents, with the
 * derived parts already resolved on the server.
 *
 * The board, the till and the bot would otherwise each work out the state, the
 * outstanding amount and the waiting time on their own, and end up disagreeing.
 */
final class OrderResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(OrderModel $order, bool $withItems = true): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'statusLabel' => $order->status->label(),
            'isOpen' => $order->status->isOpen(),
            'isInKitchen' => $order->status->isInKitchen(),

            'serviceType' => $order->service_type->value,
            'serviceTypeLabel' => $order->service_type->label(),
            'channel' => $order->channel,

            'customerName' => $order->customer_name,
            'customerPhone' => $order->customer_phone,
            'deliveryAddress' => $order->delivery_address,
            // The zone, copied onto the order like the product name: an old one reads
            // even if the owner renamed it or stopped delivering there.
            'deliveryZoneName' => $order->delivery_zone_name,

            'subtotalCents' => $order->subtotal_cents,
            'deliveryFeeCents' => $order->delivery_fee_cents,
            'totalCents' => $order->total_cents,
            'paidCents' => $order->paid_cents,
            // Computed, not stored: two fields that ought to agree end up disagreeing,
            // and the one being looked at is always the wrong one.
            'outstandingCents' => $order->outstandingCents(),
            'paymentStatus' => $order->payment_status,
            'currency' => $order->currency,
            'exchangeRate' => $order->exchange_rate === null ? null : (float) $order->exchange_rate,

            'notes' => $order->notes,
            'cancellationReason' => $order->cancellation_reason,

            'placedAt' => $order->placed_at?->toAtomString(),
            'confirmedAt' => $order->confirmed_at?->toAtomString(),
            'readyAt' => $order->ready_at?->toAtomString(),
            'deliveredAt' => $order->delivered_at?->toAtomString(),

            // The board paints "7 min ago" without the device's clock, which on a shop
            // tablet is almost never right.
            'waitingSeconds' => $order->placed_at === null
                ? 0
                : max(0, (int) round(now()->diffInSeconds($order->placed_at, absolute: true))),

            'items' => $withItems && $order->relationLoaded('items')
                ? $order->items->map(self::item(...))->all()
                : null,

            'payments' => $order->relationLoaded('payments')
                ? $order->payments->map(self::payment(...))->all()
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(OrderItemModel $item): array
    {
        return [
            'id' => $item->id,
            'productId' => $item->product_id,
            'name' => $item->product_name,
            'quantity' => $item->quantity,
            'unitPriceCents' => $item->unit_price_cents,
            'lineTotalCents' => $item->line_total_cents,
            'notes' => $item->notes,
            // Already text: the kitchen reads "No onion", not an id.
            'modifiers' => $item->relationLoaded('modifiers')
                ? $item->modifiers->map(fn ($m): array => [
                    'name' => $m->name,
                    'priceDeltaCents' => $m->price_delta_cents,
                ])->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function payment(OrderPaymentModel $payment): array
    {
        return [
            'id' => $payment->id,
            'method' => $payment->method,
            'amountCents' => $payment->amount_cents,
            'reference' => $payment->reference,

            /*
             * The file PATH does not leave here — only whether there is a
             * receipt and the API address to ask for it, which checks
             * permission and tenant before serving the photo.
             */
            'hasReceipt' => $payment->receipt_url !== null,
            'receiptUrl' => $payment->receipt_url === null
                ? null
                : "/api/v1/orders/{$payment->order_id}/payments/{$payment->id}/receipt",

            'status' => $payment->status,
            'confirmedAt' => $payment->confirmed_at?->toAtomString(),
        ];
    }
}
