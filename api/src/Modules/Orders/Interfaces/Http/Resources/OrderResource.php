<?php

declare(strict_types=1);

namespace Modules\Orders\Interfaces\Http\Resources;

use App\Models\Orders\OrderItemModel;
use App\Models\Orders\OrderModel;
use App\Models\Orders\OrderPaymentModel;

/**
 * Cómo se ve un pedido hacia el cliente.
 *
 * En camelCase y **siempre en centavos**. Y con lo derivado ya resuelto en el
 * servidor —el estado en palabras, lo que falta por cobrar, si está en la
 * cocina— para que el tablero, la caja y el bot no tengan que calcularlo cada
 * uno por su cuenta y acabar discrepando.
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

            'subtotalCents' => $order->subtotal_cents,
            'deliveryFeeCents' => $order->delivery_fee_cents,
            'totalCents' => $order->total_cents,
            'paidCents' => $order->paid_cents,
            // Calculado, no guardado: dos campos que deberían coincidir acaban
            // discrepando, y el que se mira es siempre el equivocado.
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

            // El tablero pinta «hace 7 min» sin depender del reloj del
            // dispositivo, que en una tablet de local casi nunca está bien.
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
            // Ya resueltos en texto: la cocina lee «Sin cebolla», no un id.
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
             * La RUTA del archivo no sale de aquí.
             *
             * Lo que viaja es si hay comprobante y por dónde pedirlo: una
             * dirección de la API que comprueba permiso y negocio antes de
             * servir la foto. Mandar la ruta del disco sería invitar a que
             * alguien construya con ella una URL directa el día que a alguien
             * se le ocurra publicar la carpeta.
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
