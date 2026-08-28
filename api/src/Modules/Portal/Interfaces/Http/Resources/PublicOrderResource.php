<?php

declare(strict_types=1);

namespace Modules\Portal\Interfaces\Http\Resources;

use App\Models\Orders\OrderModel;

/**
 * El pedido, como lo ve **el cliente**.
 *
 * Deliberadamente NO es el recurso del panel. Aquí no van los márgenes, ni
 * quién lo atendió, ni las notas internas, ni la lista de pagos con sus
 * referencias: quien mira esto es alguien de la calle con un enlace, y lo único
 * que necesita saber es qué pidió, cuánto es y por dónde va.
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
            // En palabras del CLIENTE, que no son las del negocio: para quien
            // está esperando comida, «sin confirmar» suena a que algo salió
            // mal, y lo que pasa es que su pedido ya llegó.
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
            // Si todavía se le espera con el comprobante, y hasta cuándo.
            'expiresAt' => $order->expires_at?->toAtomString(),
            'needsReceipt' => $order->status->value === 'pending_payment',

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
     * Lo que se le dice al cliente que está pasando.
     *
     * El negocio y el cliente miran el mismo pedido y necesitan palabras
     * distintas: «sin confirmar» es información útil para quien tiene que
     * confirmarlo, y una preocupación para quien tiene hambre.
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
     * Por dónde va, en cuatro pasos que se entienden de un vistazo.
     *
     * El cliente no quiere una máquina de estados: quiere saber si ya lo están
     * haciendo. Los ocho estados internos se agrupan aquí y no en la pantalla,
     * para que el portal y el bot cuenten lo mismo.
     *
     * @return list<array{key: string, label: string, done: bool}>
     */
    private static function steps(OrderModel $order): array
    {
        $entregado = $order->delivered_at !== null;
        $enCamino = $order->out_for_delivery_at !== null || $order->ready_at !== null;
        $cocinando = $order->confirmed_at !== null;

        return [
            ['key' => 'received', 'label' => 'Recibido', 'done' => $order->placed_at !== null],
            ['key' => 'cooking', 'label' => 'Lo estamos haciendo', 'done' => $cocinando],
            [
                'key' => 'ready',
                'label' => $order->service_type->value === 'delivery' ? 'En camino' : 'Listo para buscar',
                'done' => $enCamino,
            ],
            ['key' => 'delivered', 'label' => 'Entregado', 'done' => $entregado],
        ];
    }
}
