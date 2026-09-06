<?php

declare(strict_types=1);

namespace Modules\Orders\Application\UseCases;

use App\Models\Orders\OrderModel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Catalog\Application\Contracts\ProductCatalog;
use Modules\Orders\Application\Exceptions\OrderMovedByOther;
use Modules\Orders\Application\Exceptions\OrderNotFound;
use Modules\Orders\Domain\Events\ConfirmedOrderLine;
use Modules\Orders\Domain\Events\OrderAdvanced;
use Modules\Orders\Domain\Events\OrderConfirmed;
use Modules\Orders\Domain\ValueObjects\OrderStatus;
use Platform\Audit\AuditLogger;
use Platform\Tenancy\TenantContext;

/**
 * Moving an order to the next state, with real optimistic locking: the `UPDATE`
 * carries `where state_version = ?` and a 409 asks for a reload.
 *
 * The till and the kitchen screen look at the same order and two people tap
 * almost at once every day. Without this, whoever saves second overwrites the
 * first and nobody finds out.
 */
final class AdvanceOrder
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly Dispatcher $events,
        private readonly TenantContext $context,
        private readonly ProductCatalog $products,
    ) {}

    public function execute(string $orderId, OrderStatus $next, ?string $byName = null): OrderModel
    {
        $model = OrderModel::find($orderId) ?? throw new OrderNotFound;

        $current = $model->status;

        // Repeating the current step is not an error and spends no version: two
        // taps on "Confirm" cannot raise a red message mid-service.
        if ($current === $next) {
            return $model;
        }

        // The domain entity decides whether the transition is valid, and throws a
        // 409 saying what happened rather than "invalid transition".
        $order = OrderReader::toDomain($model);
        $order->moveTo($next);

        $stamp = self::stampColumn($next);
        $version = (int) $model->state_version;

        $affected = $this->db->table('orders')
            ->where('id', $orderId)
            ->where('tenant_id', $this->context->id())
            ->where('state_version', $version)
            ->update([
                'status' => $next->value,
                'state_version' => $version + 1,
                $stamp => now(),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            throw new OrderMovedByOther;
        }

        $this->audit->record(
            action: 'orders.advanced',
            entityType: 'order',
            entityId: $orderId,
            before: ['status' => $current->value],
            after: ['status' => $next->value],
        );

        /*
         * Confirming is what sends the order to the KITCHEN.
         *
         * By event rather than a direct call: `Orders` cannot know `Kitchen` —
         * an architecture test forbids it — and the same moment can also fire
         * the customer notice without touching this use case.
         */
        if ($next === OrderStatus::Confirmed) {
            $this->events->dispatch($this->confirmedEvent($model, $byName));
        }

        /*
         * And the thin notice for everything else. Thin on purpose: loading an
         * order's lines every time somebody taps "Delivered" would be work done
         * for nobody to read.
         */
        $this->events->dispatch(new OrderAdvanced(
            tenantId: $this->context->id(),
            orderId: $orderId,
            number: (int) $model->number,
            status: $next->value,
            previousStatus: $current->value,
        ));

        return $model->refresh();
    }

    /**
     * Assembles the event with everything the kitchen needs.
     *
     * Prep time comes from the CATALOG, not the order line: it is a fact about
     * what is sold, and adjusting the grill's time should apply to the next
     * ticket. The MAXIMUM across lines is taken, not the sum — dishes are made
     * at the same time, and summing would give half an hour for two arepas.
     */
    private function confirmedEvent(OrderModel $model, ?string $byName): OrderConfirmed
    {
        $items = $model->items()->with('modifiers')->get();

        $snapshots = $this->products->findMany(
            $items->pluck('product_id')->filter()->unique()->values()->all(),
        );

        $prepMinutes = collect($snapshots)
            ->map(fn ($snapshot): ?int => $snapshot->prepMinutes)
            ->filter()
            ->max();

        return new OrderConfirmed(
            tenantId: $this->context->id(),
            orderId: (string) $model->id,
            number: (int) $model->number,
            serviceType: $model->service_type->value,
            lines: $items->map(fn ($item): ConfirmedOrderLine => new ConfirmedOrderLine(
                productId: $item->product_id,
                name: (string) $item->product_name,
                quantity: (int) $item->quantity,
                // Already text: the kitchen reads "No onion", not an id.
                modifiers: $item->modifiers->pluck('name')->all(),
                notes: $item->notes,
            ))->all(),
            prepMinutes: $prepMinutes === null ? null : (int) $prepMinutes,
            notes: $model->notes,
            confirmedByName: $byName,
        );
    }

    private static function stampColumn(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Confirmed => 'confirmed_at',
            OrderStatus::Preparing => 'preparing_at',
            OrderStatus::Ready => 'ready_at',
            OrderStatus::OutForDelivery => 'out_for_delivery_at',
            OrderStatus::Delivered => 'delivered_at',
            OrderStatus::Cancelled => 'cancelled_at',
            OrderStatus::Placed, OrderStatus::PendingPayment => 'placed_at',
        };
    }
}
