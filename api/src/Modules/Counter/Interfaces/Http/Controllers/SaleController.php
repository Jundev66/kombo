<?php

declare(strict_types=1);

namespace Modules\Counter\Interfaces\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Counter\Application\UseCases\CompleteSale;
use Modules\Documents\Interfaces\Http\Resources\DeliveryNoteResource;
use Modules\Orders\Domain\ValueObjects\ServiceType;
use Modules\Orders\Interfaces\Http\Resources\OrderResource;

/**
 * Cobrar en el mostrador.
 *
 * Una sola llamada: lo que se llevó y cómo pagó. El servidor arma el pedido,
 * lo manda a la cocina, registra los pagos y devuelve la nota lista para
 * imprimir.
 */
final class SaleController
{
    public function __invoke(Request $request, CompleteSale $completeSale): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.modifier_ids' => ['array'],
            'items.*.modifier_ids.*' => ['uuid'],
            'items.*.notes' => ['nullable', 'string', 'max:300'],

            // Varios pagos: aquí se cobra mezclado y con uno solo no se
            // representa.
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', 'string', 'in:cash_usd,cash_bs,pago_movil,transfer,zelle,card,binance'],
            'payments.*.amount_cents' => ['required', 'integer', 'min:1'],
            'payments.*.reference' => ['nullable', 'string', 'max:120'],

            'service_type' => ['nullable', 'string', 'in:takeaway,dine_in,delivery'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_tax_id' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // No se acepta ningún importe de línea ni total: los precios los pone
        // el servidor, siempre.
        ['order' => $order, 'note' => $note] = $completeSale->execute(
            items: $data['items'],
            payments: $data['payments'],
            serviceType: ServiceType::from($data['service_type'] ?? 'takeaway'),
            customerName: $data['customer_name'] ?? null,
            customerTaxId: $data['customer_tax_id'] ?? null,
            notes: $data['notes'] ?? null,
        );

        return response()->json([
            'data' => [
                'order' => OrderResource::make($order->load('items.modifiers')),
                'note' => DeliveryNoteResource::make($note),
            ],
        ], 201);
    }
}
