<?php

declare(strict_types=1);

namespace Modules\Counter\Interfaces\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Counter\Application\UseCases\VoidSale;
use Modules\Documents\Interfaces\Http\Resources\DeliveryNoteResource;
use Modules\Orders\Interfaces\Http\Resources\OrderResource;
use Platform\Auth\ActionAuthorizer;

/**
 * Anular una venta del mostrador: se cancela el pedido y se anula su nota.
 *
 * El motivo es obligatorio. La pregunta que esto tiene que poder responder
 * dentro de tres meses no es «¿se puede anular?» sino «¿quién anuló esta venta,
 * cuándo y por qué?».
 */
final class VoidSaleController
{
    public function __invoke(
        Request $request,
        string $orderId,
        VoidSale $voidSale,
        ActionAuthorizer $authorizer,
    ): JsonResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        // Quien sólo puede solicitarlo necesita aquí el PIN de un encargado.
        // Si falta, esto responde 422 con el nombre del campo, para que la caja
        // sepa abrir el teclado del PIN en vez de quedarse sin saber qué hacer.
        $authorizedBy = $authorizer->resolve($request, 'counter.void');

        ['order' => $order, 'note' => $note] = $voidSale->execute($orderId, $data['reason'], $authorizedBy);

        return response()->json([
            'data' => [
                'order' => OrderResource::make($order->load('items.modifiers')),
                'note' => $note === null ? null : DeliveryNoteResource::make($note),
            ],
        ]);
    }
}
