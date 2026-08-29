<?php

declare(strict_types=1);

namespace Modules\Delivery\Interfaces\Http\Controllers;

use App\Models\Orders\OrderModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Delivery\Application\UseCases\TakeDelivery;
use Modules\Orders\Application\UseCases\AdvanceOrder;
use Modules\Orders\Domain\ValueObjects\OrderStatus;
use Platform\Capabilities\CurrentCapabilities;

/**
 * La pantalla del repartidor.
 *
 * Dos listas y nada más: **lo que está listo para salir** y **lo que llevo yo**.
 * Un repartidor mira esto en su teléfono, en la moto, con una mano — no explora
 * una aplicación.
 *
 * Ve lo suyo y lo que está libre. Los pedidos que lleva otro no aparecen: no
 * son asunto suyo, y una lista con las entregas de tres personas es una lista
 * donde nadie encuentra la propia.
 */
final class DeliveryController
{
    public function __construct(private readonly CurrentCapabilities $capabilities) {}

    public function index(): JsonResponse
    {
        $yo = (string) auth()->id();

        $orders = OrderModel::query()
            ->where('service_type', 'delivery')
            ->whereIn('status', [OrderStatus::Ready->value, OrderStatus::OutForDelivery->value])
            ->where(fn ($q) => $q->whereNull('courier_id')->orWhere('courier_id', $yo))
            // La más vieja primero: es el orden en el que hay que salir.
            ->orderBy('ready_at')
            ->limit(60)
            ->get();

        return response()->json([
            'data' => $orders->map(fn (OrderModel $order): array => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status->value,
                'statusLabel' => $order->status->label(),

                'customerName' => $order->customer_name,
                // El teléfono se ve: es con lo que se llama cuando no se
                // encuentra la casa, que pasa en la mitad de los repartos.
                'customerPhone' => $order->customer_phone,
                'address' => $order->delivery_address,
                'zoneName' => $order->delivery_zone_name,

                'totalCents' => $order->total_cents,
                // Lo que hay que cobrar al llegar. Cero si ya pagó, y esa
                // diferencia es lo único que el repartidor necesita saber del
                // dinero.
                'toCollectCents' => max(0, (int) $order->total_cents - (int) $order->paid_cents),

                'isMine' => (string) $order->courier_id === $yo,
                'courierName' => $order->courier_name,
                'readyAt' => $order->ready_at?->toAtomString(),
            ])->all(),
        ]);
    }

    public function take(string $id, TakeDelivery $take): JsonResponse
    {
        $user = auth()->user();

        $take->execute($id, (string) $user?->getKey(), (string) $user?->name);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function release(string $id, TakeDelivery $take): JsonResponse
    {
        $take->release($id, (string) auth()->id());

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * Salgo con él, o ya lo entregué.
     *
     * Pasa por el mismo caso de uso que el panel y la cocina: la máquina de
     * estados es una sola, y un atajo aquí sería un pedido que avanza sin
     * pasar por sus reglas ni por la bitácora.
     */
    public function advance(Request $request, string $id, AdvanceOrder $advance): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:out_for_delivery,delivered'],
        ]);

        $order = OrderModel::findOrFail($id);

        // Sólo lo suyo: marcar entregado lo de otro es cómo se pierde el rastro
        // de quién llevó qué.
        abort_if((string) $order->courier_id !== (string) auth()->id(), 403, 'Ese pedido no lo llevas tú.');

        $advance->execute($id, OrderStatus::from($data['status']), byName: (string) auth()->user()?->name);

        return response()->json(['data' => ['ok' => true]]);
    }
}
