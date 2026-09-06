<?php

declare(strict_types=1);

namespace Modules\Orders\Application\UseCases;

use App\Models\Orders\OrderItemModel;
use App\Models\Orders\OrderItemModifierModel;
use App\Models\Orders\OrderModel;
use Modules\Orders\Domain\Entities\Order;
use Modules\Orders\Domain\ValueObjects\OrderLine;
use Modules\Orders\Domain\ValueObjects\OrderLineModifier;
use Shared\Domain\ValueObjects\Money;

/**
 * From the row to the domain.
 *
 * In Application rather than Domain on purpose: the domain does not know
 * Eloquent exists, and an architecture test verifies it.
 */
final class OrderReader
{
    public static function toDomain(OrderModel $model): Order
    {
        $lines = $model->relationLoaded('items')
            ? $model->items
            : $model->items()->with('modifiers')->get();

        return Order::rehydrate(
            id: (string) $model->id,
            status: $model->status,
            serviceType: $model->service_type,
            lines: $lines->map(self::toLine(...))->all(),
            deliveryFee: Money::fromCents((int) $model->delivery_fee_cents, (string) $model->currency),
            notes: $model->notes,
            cancellationReason: $model->cancellation_reason,
            timestamps: [
                'placed_at' => $model->placed_at,
                'confirmed_at' => $model->confirmed_at,
                'preparing_at' => $model->preparing_at,
                'ready_at' => $model->ready_at,
                'out_for_delivery_at' => $model->out_for_delivery_at,
                'delivered_at' => $model->delivered_at,
                'cancelled_at' => $model->cancelled_at,
            ],
        );
    }

    private static function toLine(OrderItemModel $item): OrderLine
    {
        $modifiers = $item->relationLoaded('modifiers') ? $item->modifiers : $item->modifiers()->get();

        return new OrderLine(
            productId: (string) ($item->product_id ?? ''),
            productName: (string) $item->product_name,
            unitPrice: Money::fromCents((int) $item->unit_price_cents),
            quantity: (int) $item->quantity,
            modifiers: $modifiers->map(fn (OrderItemModifierModel $m): OrderLineModifier => new OrderLineModifier(
                modifierId: $m->modifier_id,
                name: (string) $m->name,
                priceDelta: Money::fromCents((int) $m->price_delta_cents),
            ))->all(),
            notes: $item->notes,
        );
    }
}
