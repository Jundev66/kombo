<?php

declare(strict_types=1);

namespace Modules\Customers\Interfaces\Http\Controllers;

use App\Models\Customers\CustomerModel;
use App\Models\Orders\OrderModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La libreta de clientes.
 *
 * Ordenada por **lo último que pidieron**, no alfabéticamente: la pregunta que
 * se hace aquí es «¿quién viene seguido?» y «¿qué le gustaba a este?», no
 * «¿dónde está González?».
 */
final class CustomerController
{
    public function index(Request $request): JsonResponse
    {
        $buscar = $request->string('buscar')->toString();

        $customers = CustomerModel::query()
            ->when(
                $buscar !== '',
                function ($q) use ($buscar) {
                    /*
                     * Por nombre con `ilike`, y por teléfono por su HASH.
                     *
                     * El número está cifrado y el cifrado de Laravel no es
                     * determinista, así que no se puede buscar por igualdad ni
                     * por parecido. El hash resuelve el número completo, que es
                     * como la gente lo busca de todas formas: lo tiene delante
                     * en el chat.
                     */
                    return $q->where('name', 'ilike', "%{$buscar}%")
                        ->orWhere('phone_hash', CustomerModel::hashOf($buscar));
                },
            )
            ->orderByDesc('last_order_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $customers->map(self::asArray(...))->all(),
        ]);
    }

    /** La ficha, con lo que ha pedido. */
    public function show(string $id): JsonResponse
    {
        $customer = CustomerModel::find($id) ?? throw new NotFoundHttpException('Ese cliente no existe.');

        // Se unen por teléfono y no por una clave foránea: los pedidos guardan
        // el número copiado, así que uno de hace seis meses se lee entero
        // aunque el cliente se borre.
        $orders = OrderModel::where('customer_phone', $customer->phone)
            ->orderByDesc('placed_at')
            ->limit(30)
            ->get();

        return response()->json([
            'data' => [
                ...self::asArray($customer),
                'orders' => $orders->map(fn (OrderModel $order): array => [
                    'id' => $order->id,
                    'number' => $order->number,
                    'status' => $order->status->value,
                    'statusLabel' => $order->status->label(),
                    'channel' => $order->channel,
                    'totalCents' => $order->total_cents,
                    'placedAt' => $order->placed_at?->toAtomString(),
                ])->all(),
            ],
        ]);
    }

    /**
     * La nota: «no le pongan cebolla», «paga siempre en efectivo».
     *
     * Es lo único que se edita a mano aquí. El resto lo lleva el sistema solo.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $customer = CustomerModel::find($id) ?? throw new NotFoundHttpException('Ese cliente no existe.');

        $customer->update($data);

        return response()->json(['data' => self::asArray($customer->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function asArray(CustomerModel $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'notes' => $customer->notes,
            'ordersCount' => $customer->orders_count,
            'spentCents' => $customer->spent_cents,
            'lastOrderAt' => $customer->last_order_at?->toAtomString(),
        ];
    }
}
