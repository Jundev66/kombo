<?php

declare(strict_types=1);

namespace Modules\Portal\Interfaces\Http\Resources;

use App\Models\Orders\OrderModel;

/**
 * The order, as the CUSTOMER sees it.
 *
 * Deliberately not the dashboard's resource: no margins, no who handled it, no
 * internal notes, no payment references. Somebody off the street with a link
 * needs what they ordered, how much it is, and where it has got to.
 */
final class PublicOrderResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(OrderModel $order): array
    {
        $order->loadMissing('items.modifiers');

        return [
            'token' => $order->public_token,
            'number' => $order->number,

            'status' => $order->status->value,
            // In the CUSTOMER's words: to somebody waiting on food, "unconfirmed"
            // sounds like something went wrong, when their order simply arrived.
            'statusLabel' => self::publicLabel($order),
            'steps' => self::steps($order),

            'serviceType' => $order->service_type->value,
            'serviceTypeLabel' => $order->service_type->label(),

            'customerName' => $order->customer_name,
            'deliveryAddress' => $order->delivery_address,
            'deliveryZoneName' => $order->delivery_zone_name,

            'subtotalCents' => $order->subtotal_cents,
            'deliveryFeeCents' => $order->delivery_fee_cents,
            'totalCents' => $order->total_cents,
            'currency' => $order->currency,
            'exchangeRate' => $order->exchange_rate === null ? null : (float) $order->exchange_rate,

            'paymentStatus' => $order->payment_status,
            // Whether the receipt is still being waited for, and until when.
            'expiresAt' => $order->expires_at?->toAtomString(),
            'needsReceipt' => $order->status->value === 'pending_payment',

            /*
             * Both deadlines in SECONDS, computed here rather than trusting the
             * device clock — this one belongs to somebody out in the street.
             * The number shown is how long before their order cancels itself,
             * so showing it wrong is worse than not showing it.
             *
             * Never negative: past the deadline it is zero, and the screen says
             * it may be cancelled at any moment.
             */
            'waitingSeconds' => $order->placed_at === null
                ? 0
                : max(0, (int) round(now()->diffInSeconds($order->placed_at, absolute: true))),

            'expiresInSeconds' => $order->expires_at === null
                ? null
                : max(0, (int) round(now()->diffInSeconds($order->expires_at, absolute: false))),

            'notes' => $order->notes,
            'cancellationReason' => $order->cancellation_reason,
            'placedAt' => $order->placed_at?->toAtomString(),

            'items' => $order->items->map(fn ($item): array => [
                'name' => $item->product_name,
                'quantity' => $item->quantity,
                'lineTotalCents' => $item->line_total_cents,
                'modifiers' => $item->modifiers->pluck('name')->all(),
            ])->all(),
        ];
    }

    /**
     * What the customer is told is happening.
     *
     * The tenant and the customer look at the same order and need different
     * words: "unconfirmed" is useful to whoever has to confirm it, and a worry
     * to whoever is hungry.
     */
    private static function publicLabel(OrderModel $order): string
    {
        return match ($order->status->value) {
            'pending_payment' => 'Esperando tu comprobante',
            'placed' => 'Recibido, ya lo vemos',
            'confirmed', 'preparing' => 'Lo estamos haciendo',
            'ready' => $order->service_type->value === 'delivery'
                ? 'Listo, sale enseguida'
                : 'Listo para buscar',
            'out_for_delivery' => 'En camino',
            'delivered' => 'Entregado',
            'cancelled' => 'Cancelado',
            default => $order->status->label(),
        };
    }

    /**
     * Where it has got to, in four steps understood at a glance.
     *
     * The eight internal states are grouped here rather than on the screen, so
     * the portal and the bot tell the same story.
     *
     * @return list<array{key: string, label: string, done: bool}>
     */
    private static function steps(OrderModel $order): array
    {
        $delivered = $order->delivered_at !== null;
        $onTheWay = $order->out_for_delivery_at !== null || $order->ready_at !== null;
        $cooking = $order->confirmed_at !== null;

        return [
            ['key' => 'received', 'label' => 'Recibido', 'done' => $order->placed_at !== null],
            ['key' => 'cooking', 'label' => 'Lo estamos haciendo', 'done' => $cooking],
            [
                'key' => 'ready',
                'label' => $order->service_type->value === 'delivery' ? 'En camino' : 'Listo para buscar',
                'done' => $onTheWay,
            ],
            ['key' => 'delivered', 'label' => 'Entregado', 'done' => $delivered],
        ];
    }
}
