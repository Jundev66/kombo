<?php

declare(strict_types=1);

namespace Modules\Customers\Interfaces\Http\Controllers;

use App\Models\Customers\CustomerModel;
use App\Models\Orders\OrderModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The customer book, ordered by the last thing they ordered rather than
 * alphabetically: the question here is "who comes back often?".
 */
final class CustomerController
{
    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->toString();

        $customers = CustomerModel::query()
            ->when(
                $search !== '',
                function ($q) use ($search) {
                    /*
                     * By name with `ilike`, by phone via its HASH. The number
                     * is encrypted and not deterministic, so it cannot be
                     * matched by similarity — and the hash resolves the whole
                     * number, which is how people search anyway.
                     */
                    return $q->where('name', 'ilike', "%{$search}%")
                        ->orWhere('phone_hash', CustomerModel::hashOf($search));
                },
            )
            ->orderByDesc('last_order_at')
            /*
             * Paginated, not a bare `limit(100)`. With the plain cap a tenant
             * with four hundred customers saw a hundred and no signal at all,
             * which is the worst way to truncate a list.
             */
            ->paginate(100);

        return response()->json([
            'data' => $customers->getCollection()->map(self::asArray(...))->all(),
            'meta' => [
                'page' => $customers->currentPage(),
                'lastPage' => $customers->lastPage(),
                'total' => $customers->total(),
            ],
        ]);
    }

    /** The record, with what they have ordered. */
    public function show(string $id): JsonResponse
    {
        $customer = CustomerModel::find($id) ?? throw new NotFoundHttpException('Ese cliente no existe.');

        // Joined by phone rather than a foreign key: orders store the number as a
        // copy, so an old one reads in full even if the customer is deleted.
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
     * The note: "no onion for them", "always pays cash". The only thing edited
     * by hand here; the system keeps the rest up to date.
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
