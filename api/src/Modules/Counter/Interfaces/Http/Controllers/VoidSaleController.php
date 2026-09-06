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
 * Voiding a counter sale: the order is cancelled and its note voided.
 *
 * A reason is required. The question to answer three months from now is not
 * "can it be voided?" but "who voided this sale, when and why?".
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

        // Whoever can only request it needs a manager's PIN here. Missing, this
        // answers 422 with the field name so the till opens the PIN pad instead of
        // leaving the cashier with nothing to do.
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
