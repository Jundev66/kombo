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
 * Pedir y seguir el pedido, sin cuenta.
 *
 * El seguimiento va por un **token del propio pedido**, no por su id: quien
 * tiene el enlace ve ese pedido y ninguno más. Es lo que permite que la
 * pantalla de pago sobreviva a que el cliente cierre el navegador para ir a la
 * aplicación del banco y vuelva media hora después.
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

            // El teléfono no es opcional: es cómo se avisa de que está listo, y
            // cómo se llama cuando el repartidor no encuentra la casa.
            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            'customer_phone' => ['required', 'string', 'min:7', 'max:20'],

            'delivery_zone_id' => ['nullable', 'uuid'],
            'delivery_address' => ['nullable', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:400'],
        ]);

        // Ningún importe llega del cliente: ni de línea, ni de reparto, ni
        // total. Todo sale del catálogo y de la zona.
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
     * El pedido de ese token, dentro de este negocio.
     *
     * RLS ya filtra por negocio, así que un token de otro negocio no aparece
     * aunque se acierte. Y **404 siempre**: un 403 confirmaría que ese token
     * existe en algún sitio.
     */
    public static function byToken(string $token): OrderModel
    {
        return OrderModel::where('public_token', $token)->first()
            ?? throw new NotFoundHttpException('Ese pedido no existe. Revisa el enlace.');
    }
}
