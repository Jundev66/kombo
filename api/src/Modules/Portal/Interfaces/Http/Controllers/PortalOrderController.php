<?php

declare(strict_types=1);

namespace Modules\Portal\Interfaces\Http\Controllers;

use App\Models\Orders\OrderModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Orders\Domain\ValueObjects\ServiceType;
use Modules\Portal\Application\UseCases\PlacePortalOrder;
use Modules\Portal\Interfaces\Http\Resources\PublicOrderResource;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ordering and following the order, with no account.
 *
 * Tracking goes by a token belonging to the order, not its id: whoever has the
 * link sees that order and no other, and the payment screen survives the
 * browser closing to visit the banking app.
 */
final class PortalOrderController
{
    public function store(Request $request, PlacePortalOrder $place): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.modifier_ids' => ['array', 'max:20'],
            'items.*.modifier_ids.*' => ['uuid'],
            'items.*.notes' => ['nullable', 'string', 'max:200'],

            'service_type' => ['required', 'string', 'in:takeaway,delivery'],
            'payment_method' => ['required', 'string', 'in:cash,pago_movil'],

            // The phone number is not optional: it is how they are told it is ready,
            // and how they are called when the courier cannot find the house.
            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            'customer_phone' => ['required', 'string', 'min:7', 'max:20'],

            'delivery_zone_id' => ['nullable', 'uuid'],
            'delivery_address' => ['nullable', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:400'],
        ]);

        // No amount arrives from the client — not per line, not delivery, not the
        // total. It all comes from the catalog and the zone.
        $order = $place->execute(
            items: $data['items'],
            serviceType: ServiceType::from($data['service_type']),
            paymentMethod: $data['payment_method'],
            customerName: $data['customer_name'],
            customerPhone: $data['customer_phone'],
            deliveryZoneId: $data['delivery_zone_id'] ?? null,
            deliveryAddress: $data['delivery_address'] ?? null,
            notes: $data['notes'] ?? null,
        );

        return response()->json(['data' => PublicOrderResource::make($order)], 201);
    }

    public function show(string $token): JsonResponse
    {
        return response()->json(['data' => PublicOrderResource::make(self::byToken($token))]);
    }

    /**
     * That token's order, within this tenant.
     *
     * RLS already filters by tenant, and it is 404 always: a 403 would confirm
     * the token exists somewhere.
     */
    public static function byToken(string $token): OrderModel
    {
        return OrderModel::where('public_token', $token)->first()
            ?? throw new NotFoundHttpException('Ese pedido no existe. Revisa el enlace.');
    }
}
