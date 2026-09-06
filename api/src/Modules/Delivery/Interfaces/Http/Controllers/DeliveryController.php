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
 * The courier's screen: what is ready to go out, and what I am carrying.
 *
 * Looked at on a phone, on the bike, one-handed. Orders somebody else is
 * carrying do not appear — a list with three people's deliveries is a list
 * where nobody finds their own.
 */
final class DeliveryController
{
    public function __construct(private readonly CurrentCapabilities $capabilities) {}

    public function index(): JsonResponse
    {
        $ownOnes = (string) auth()->id();

        $orders = OrderModel::query()
            ->where('service_type', 'delivery')
            ->whereIn('status', [OrderStatus::Ready->value, OrderStatus::OutForDelivery->value])
            ->where(fn ($q) => $q->whereNull('courier_id')->orWhere('courier_id', $ownOnes))
            // Oldest first: the order they have to go out in.
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
                // The phone number is visible: it is what you call with when you cannot
                // find the house, which is half of all deliveries.
                'customerPhone' => $order->customer_phone,
                'address' => $order->delivery_address,
                'zoneName' => $order->delivery_zone_name,

                'totalCents' => $order->total_cents,
                // What to collect on arrival. Zero if already paid, and that difference
                // is all the courier needs to know about the money.
                'toCollectCents' => max(0, (int) $order->total_cents - (int) $order->paid_cents),

                'isMine' => (string) $order->courier_id === $ownOnes,
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
     * Going out with it, or delivered.
     *
     * Through the same use case as the dashboard and the kitchen: one state
     * machine, and a shortcut here would be an order advancing without its
     * rules or the audit log.
     */
    public function advance(Request $request, string $id, AdvanceOrder $advance): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:out_for_delivery,delivered'],
        ]);

        $order = OrderModel::findOrFail($id);

        // Only their own: marking somebody else's delivered is how the trail of
        // who took what gets lost.
        abort_if((string) $order->courier_id !== (string) auth()->id(), 403, 'Ese pedido no lo llevas tú.');

        $advance->execute($id, OrderStatus::from($data['status']), byName: (string) auth()->user()?->name);

        return response()->json(['data' => ['ok' => true]]);
    }
}
